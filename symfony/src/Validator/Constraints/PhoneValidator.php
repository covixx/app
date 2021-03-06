<?php

namespace App\Validator\Constraints;

use App\Manager\PhoneManager;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberType;
use libphonenumber\PhoneNumberUtil;
use Symfony\Component\Form\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Contracts\Translation\TranslatorInterface;

class PhoneValidator extends ConstraintValidator
{
    /**
     * @var PhoneManager
     */
    private $phoneManager;

    /**
     * @var TranslatorInterface
     */
    private $translator;

    public function __construct(PhoneManager $phoneManager, TranslatorInterface $translator)
    {
        $this->phoneManager = $phoneManager;
        $this->translator   = $translator;
    }

    /**
     * @param \App\Entity\Phone $value
     * @param Constraint        $constraint
     */
    public function validate($value, Constraint $constraint)
    {
        if (!$constraint instanceof Phone) {
            throw new UnexpectedTypeException($constraint, Phone::class);
        }

        // This phone number is invalid
        $phoneUtil = PhoneNumberUtil::getInstance();
        try {
            $parsed = $phoneUtil->parse($value->getE164(), \App\Entity\Phone::DEFAULT_LANG);
        } catch (NumberParseException $e) {
            $this->context
                ->buildViolation(
                    $this->translator->trans('phone_card.error_invalid')
                )
                ->atPath('editor')
                ->addViolation();

            return;
        }

        if (PhoneNumberType::MOBILE !== $phoneUtil->getNumberType($parsed)) {
            $this->context
                ->buildViolation(
                    $this->translator->trans('phone_card.error_not_mobile')
                )
                ->atPath('editor')
                ->addViolation();
        }

        $phone = $this->phoneManager->findOneByPhoneNumber($value);

        // This phone number is already taken by someone else
        if ($phone && $phone->getVolunteer() && $value->getVolunteer()
            && $phone->getVolunteer()->getId() !== $value->getVolunteer()->getId()) {
            $this->context
                ->buildViolation(
                    $this->translator->trans('phone_card.error_taken', [
                        '%nivol%'          => $phone->getVolunteer()->getNivol(),
                        '%truncated_name%' => $phone->getVolunteer()->getTruncatedName(),
                    ])
                )
                ->atPath('editor')
                ->addViolation();

            return;
        }
    }
}