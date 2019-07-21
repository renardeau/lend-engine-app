<?php

namespace AppBundle\Controller\MemberSite;

use AppBundle\Entity\CreditCard;
use AppBundle\Entity\Membership;
use AppBundle\Entity\Note;
use AppBundle\Entity\Payment;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class SubscribeController extends Controller
{

    /**
     * @param Request $request
     * @return Response
     * @Route("member/subscribe", name="subscribe")
     *
     * New registrations are directed here following a signup if there is a membership type set to 'self serve'
     * If the membership type has a value, then we need to have card payment first
     *
     */
    public function subscribeAction(Request $request)
    {

        /** @var \AppBundle\Entity\Contact $contact */
        $contact = $this->getUser();

        $em = $this->getDoctrine()->getManager();

        /** @var \AppBundle\Services\Contact\ContactService $contactService */
        $contactService = $this->get('service.contact');

        /** @var \AppBundle\Repository\MembershipTypeRepository $membershipTypeRepo */
        $membershipTypeRepo = $em->getRepository('AppBundle:MembershipType');

        /** @var \AppBundle\Services\Payment\PaymentService $paymentService */
        $paymentService = $this->get('service.payment');

        /** @var \AppBundle\Repository\PaymentMethodRepository $pmRepo */
        $pmRepo = $em->getRepository('AppBundle:PaymentMethod');

        // Deal with form submission
        if ($membershipTypeId = $request->get('membershipTypeId')) {

            $amount = $request->get('paymentAmount');
            $paymentOk = true;

            if ($amount > 0) {

                // We need to have a success from Stripe
                if (!$paymentMethodId = $request->get('paymentMethodId')) {
                    $this->addFlash("error", "No payment method was set, but the membership type has an amount.");
                    return $this->redirectToRoute('fos_user_profile_show');
                }

                $paymentMethod   = $pmRepo->find($paymentMethodId);
                $token           = $request->get('stripeToken');

                // Add Stripe fee
                $feeAmount = (float)$this->get('settings')->getSettingValue('stripe_fee');
                $amount += $feeAmount;

                // The membership fee
                $charge = new Payment();
                $charge->setAmount(-$amount);
                $charge->setContact($contact);
                $charge->setCreatedBy($contact);
                $charge->setNote("Membership fee (self serve).");

                if (!$paymentService->create($charge, null)) {
                    $paymentOk = false;
                    foreach ($paymentService->errors AS $error) {
                        $this->addFlash('error', $error);
                    }
                }

                // The payment for the charge
                $payment = new Payment();
                $payment->setAmount($amount);
                $payment->setContact($contact);
                $payment->setCreatedBy($contact);
                $payment->setPaymentMethod($paymentMethod);
                $payment->setNote("Payment for membership fee.");

                if ($token) {
                    $cardDetails = [
                        'token' => $token
                    ];
                    if (!$paymentService->create($payment, $cardDetails)) {
                        $paymentOk = false;
                        foreach ($paymentService->errors AS $error) {
                            $this->addFlash('error', $error);
                        }
                    }
                } else {
                    // error
                    $paymentOk = false;
                    $this->addFlash('error', "We couldn't find any card details. Please contact us.");
                }

            }

            if ($paymentOk == true) {

                $membership = new Membership();
                $membership->setContact($contact);
                $membership->setCreatedBy($contact);
                $membership->setPrice($amount);

                $mType = $membershipTypeRepo->find($membershipTypeId);
                $membership->setMembershipType($mType);
                $duration = $mType->getDuration();

                // Work out how many days left on the existing membership
                // If it's a renewal (same type) and less than 14 days to run, set end date based on end of current membership
                $calculateExpiryBasedOnCurrentExpiryDate = false;
                if ($activeMembership = $contact->getActiveMembership()) {
                    $dateDiff = $activeMembership->getExpiresAt()->diff(new \DateTime());
                    if ($dateDiff->days < 14 && $activeMembership->getMembershipType() == $mType) {
                        $calculateExpiryBasedOnCurrentExpiryDate = true;
                    }
                }

                // Always start from now
                // The previous will be expired so this one will start early
                $startsAt = new \DateTime();
                if ($calculateExpiryBasedOnCurrentExpiryDate == true) {
                    // A renewal created before previous membership expires
                    $expiresAt = $activeMembership->getExpiresAt();
                } else {
                    // A new subscription
                    $expiresAt = clone $startsAt;
                }
                $expiresAt->modify("+ {$duration} days");

                $membership->setStartsAt($startsAt);
                $membership->setExpiresAt($expiresAt);

                // save the new membership
                $em->persist($membership);

                if (isset($payment)) {
                    $payment->setMembership($membership);
                    $em->persist($payment);
                }

                if (isset($charge)) {
                    $charge->setMembership($membership);
                    $em->persist($charge);
                }

                // Switch the contact to this new membership
                $contact->setActiveMembership($membership);

                // If there was a previous one, expire it prematurely
                if ($activeMembership) {
                    $activeMembership->setStatus(Membership::SUBS_STATUS_EXPIRED);
                    $em->persist($activeMembership);
                }

                // update the contact
                $em->persist($contact);

                $note = new Note();
                $note->setContact($contact);
                $note->setCreatedBy($contact);
                $note->setCreatedAt(new \DateTime());
                $note->setText("Subscribed to ".$mType->getName()." membership.");
                $em->persist($note);

                try {
                    $em->flush();
                    $this->addFlash('success', "Your subscription is complete. You're now a {$mType->getName()} member.");
                    $contactService->recalculateBalance($contact);
                } catch (\Exception $generalException) {
                    $this->addFlash('error', 'There was an error creating your membership.');
                    $this->addFlash('error', $generalException->getMessage());
                }

            } else {

            }

            return $this->redirectToRoute('fos_user_profile_show');
        }

        // Get the available self serve memberships to give to the member as choices
        // Currently only one supported
        $filter = ['isSelfServe' => true];
        $availableMembershipTypes = $membershipTypeRepo->findBy($filter);

        return $this->render(
            'member_site/pages/subscribe.html.twig',
            [
                'user'    => $contact,
                'contact' => $contact,
                'membershipTypes' => $availableMembershipTypes
            ]
        );

    }

}
