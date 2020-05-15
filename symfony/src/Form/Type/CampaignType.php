<?php

namespace App\Form\Type;

use App\Form\Model\Campaign as CampaignModel;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class CampaignType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        parent::buildForm($builder, $options);

        $builder
            ->add('label', TextType::class, [
                'label'    => 'form.campaign.fields.label',
                'required' => false,
            ])
            ->add('type', TypesType::class)
            ->add('communication', CommunicationType::class, [
                'label' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults([
            'data_class' => CampaignModel::class,
        ]);
    }
}