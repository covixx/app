<?php

namespace Bundles\PasswordLoginBundle\Form\Type;

use Bundles\PasswordLoginBundle\Manager\CaptchaManager;
use EWZ\Bundle\RecaptchaBundle\Form\Type\EWZRecaptchaType;
use EWZ\Bundle\RecaptchaBundle\Validator\Constraints\IsTrue as RecaptchaTrue;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Encoder\BCryptPasswordEncoder;
use Symfony\Component\Validator\Constraints;

class ConnectType extends AbstractType
{
    /**
     * @var CaptchaManager
     */
    private $captchaManager;

    /**
     * @var RequestStack
     */
    private $requestStack;

    public function __construct(CaptchaManager $captchaManager, RequestStack $requestStack)
    {
        $this->captchaManager = $captchaManager;
        $this->requestStack   = $requestStack;
    }

    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('username', Type\EmailType::class, [
                'label'       => 'password_login.connect.email',
                'required'    => true,
                'constraints' => [
                    new Constraints\Length(['min' => 8]),
                ],
            ])
            ->add('password', Type\PasswordType::class, [
                'label'       => 'password_login.connect.password',
                'constraints' => new Constraints\Length([
                    'min' => 8,
                    'max' => BCryptPasswordEncoder::MAX_PASSWORD_LENGTH,
                ]),
            ]);

        $ip = $this->requestStack->getMasterRequest()->getClientIp();

        if (!$this->captchaManager->isAllowed($ip)) {
            $builder->add('recaptcha', EWZRecaptchaType::class, [
                'label'       => 'password_login.connect.captcha',
                'constraints' => [
                    new RecaptchaTrue(),
                ],
            ]);
        }

        $builder
            ->add('submit', Type\SubmitType::class, [
                'label' => 'password_login.connect.connect',
            ]);
    }
}
