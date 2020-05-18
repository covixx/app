<?php

namespace App\Controller;

use App\Base\BaseController;
use App\Communication\Processor\ProcessorInterface;
use App\Entity\Campaign;
use App\Entity\Communication;
use App\Entity\Message;
use App\Form\Model\Communication as CommunicationModel;
use App\Form\Type\CampaignType;
use App\Form\Type\CommunicationType;
use App\Manager\AnswerManager;
use App\Manager\CampaignManager;
use App\Manager\CommunicationManager;
use App\Manager\MediaManager;
use App\Manager\MessageManager;
use App\Manager\TagManager;
use App\Manager\UserManager;
use App\Manager\VolunteerManager;
use App\Services\MessageFormatter;
use App\Tools\GSM;
use App\Tools\Random;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Entity;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\IsGranted;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @Route(name="communication_")
 */
class CommunicationController extends BaseController
{
    /**
     * @var CampaignManager
     */
    private $campaignManager;

    /**
     * @var CommunicationManager
     */
    private $communicationManager;

    /**
     * Message formatter, used for previsualization
     *
     * @var MessageFormatter
     */
    private $formatter;

    /**
     * @var TagManager
     */
    private $tagManager;

    /**
     * @var VolunteerManager
     */
    private $volunteerManager;

    /**
     * @var MessageManager
     */
    private $messageManager;

    /**
     * @var AnswerManager
     */
    private $answerManager;

    /**
     * @var UserManager
     */
    private $userManager;

    /**
     * @var MediaManager
     */
    private $mediaManager;

    public function __construct(CampaignManager $campaignManager, CommunicationManager $communicationManager, MessageFormatter $formatter, TagManager $tagManager, VolunteerManager $volunteerManager, MessageManager $messageManager, AnswerManager $answerManager, UserManager $userManager, MediaManager $mediaManager)
    {
        $this->campaignManager = $campaignManager;
        $this->communicationManager = $communicationManager;
        $this->formatter = $formatter;
        $this->tagManager = $tagManager;
        $this->volunteerManager = $volunteerManager;
        $this->messageManager = $messageManager;
        $this->answerManager = $answerManager;
        $this->userManager = $userManager;
        $this->mediaManager = $mediaManager;
    }

    /**
     * @Route(path="campaign/{id}", name="index", requirements={"id" = "\d+"})
     * @IsGranted("CAMPAIGN", subject="campaign")
     */
    public function indexAction(Campaign $campaign)
    {
        $this->get('session')->save();

        return $this->render('status_communication/index.html.twig', [
            'campaign'   => $campaign,
            'skills'     => $this->tagManager->findAll(),
            'progress'   => $campaign->getCampaignProgression(),
        ]);
    }

    /**
     * @Route(path="campaign/{id}/poll", name="poll", requirements={"id" = "\d+"})
     * @IsGranted("CAMPAIGN", subject="campaign")
     */
    public function pollAction(Campaign $campaign)
    {
        $this->get('session')->save();

        return new JsonResponse(
            $campaign->getCampaignStatus()
        );
    }

    /**
     * @Route(
     *     name="add",
     *     path="campaign/{id}/add-communication",
     *     requirements={"id" = "\d+"}
     * )
     * @IsGranted("CAMPAIGN", subject="campaign")
     * @Method("POST")
     */
    public function addCommunicationAction(Request $request, Campaign $campaign)
    {
        $user = $this->getUser();

        if (!$user->getVolunteer() || !$user->getStructures()->count()) {
            return $this->redirectToRoute('home');
        }

        $selection = json_decode($request->request->get('volunteers', '[]'), true);

        foreach ($selection as $volunteerId) {
            $volunteer = $this->volunteerManager->find($volunteerId);
            if (!$volunteer) {
                throw $this->createNotFoundException();
            }
        }

        // We should access the form using GET method, thus we need to store
        // the volunteer selection in the session. But in the meantime, we
        // should allow the dispatcher to create several new communications
        // on separate tabs.
        $selections = $this->get('session')->get('add-communication', []);
        if (!isset($selections[$campaign->getId()])) {
            $selections[$campaign->getId()] = [];
        }
        $key                                  = Random::generate(8);
        $selections[$campaign->getId()][$key] = $selection;
        if ($count = count($selections[$campaign->getId()]) > 100) {
            $selections[$campaign->getId()] = array_slice($selections[$campaign->getId()], $count - 100);
        }
        $this->get('session')->set('add-communication', $selections);

        return $this->redirectToRoute('communication_new', [
            'id'  => $campaign->getId(),
            'key' => $key,
        ]);
    }

    /**
     * @Route(
     *     name="new",
     *     path="campaign/{id}/new-communication/{key}",
     *     defaults={"key" = null},
     *     requirements={"id" = "\d+"}
     * )
     * @IsGranted("CAMPAIGN", subject="campaign")
     */
    public function newCommunicationAction(Request $request, Campaign $campaign, ?string $key)
    {
        $user = $this->getUser();

        if (!$user->getVolunteer() || !$user->getStructures()->count()) {
            return $this->redirectToRoute('home');
        }

        // If volunteers selection have been made on the communication page,
        // restore it from the session.
        $volunteers = [];
        if (!is_null($key)) {
            $selection = $this->get('session')->get('add-communication', [])[$campaign->getId()][$key] ?? [];

            foreach ($selection as $volunteerId) {
                $volunteer = $this->volunteerManager->find($volunteerId);
                if ($volunteer) {
                    $volunteers[] = $volunteer->getNivol();
                }
            }

            $this->get('session')->remove('add-communication');
        }

        /**
         * @var CommunicationModel
         */
        $communication             = new CommunicationModel();
        $communication->audience = $volunteers;
        $communication->answers    = [];

        $form = $this
            ->createForm(CommunicationType::class, $communication)
            ->handleRequest($request);

        // Creating the new communication is form has been submitted
        if ($form->isSubmitted() && $form->isValid()) {
            $this->communicationManager->launchNewCommunication($campaign, $communication);

            return $this->redirect($this->generateUrl('communication_index', [
                'id' => $campaign->getId(),
            ]));
        }

        return $this->render('new_communication/page.html.twig', [
            'campaign'   => $campaign,
            'volunteers' => $volunteers,
            'form'       => $form->createView(),
        ]);
    }

    /**
     * @Route(path="campaign/preview", name="preview")
     */
    public function previewCommunicationAction(Request $request)
    {
        $communicationModel = $this->getCommunicationFromRequest($request);

        if (!$communicationModel->message) {
            return new JsonResponse(['success' => false]);
        }

        $communicationEntity = $this->communicationManager->createCommunication($communicationModel);

        $message = new Message();
        $message->setCommunication($communicationEntity);
        $message->setPrefix('X');
        $message->setCode('xxxxxxxx');

        $content = $this->formatter->formatMessageContent($message);
        $parts   = GSM::getSMSParts($content);
        $estimated = $communicationEntity->getEstimatedCost($content);

        return new JsonResponse([
            'success' => true,
            'message' => htmlentities($content),
            'cost'    => count($parts),
            'price'   => round($estimated, 2),
            'length'  => array_sum(array_map('mb_strlen', $parts)),
        ]);
    }

    /**
     * @Route(path="campaign/play", name="play")
     */
    public function playCommunication(Request $request)
    {
        $communicationModel = $this->getCommunicationFromRequest($request);

        if (!$communicationModel->message) {
            return new JsonResponse(['success' => false]);
        }

        $communicationEntity = $this->communicationManager->createCommunication($communicationModel);

        $message = new Message();
        $message->setCommunication($communicationEntity);
        $message->setPrefix('X');
        $message->setCode('xxxxxxxx');

        $media = $this->mediaManager->createMp3(
            $this->formatter->formatMessageContent($message)
        );

        return new JsonResponse([
            'success' => true,
            'player' => $this->renderView('new_communication/player.html.twig', [
                'media' => $media,
            ])
        ]);
    }

    /**
     * @Route(
     *     name="answers",
     *     path="campaign/answers"
     * )
     */
    public function answersAction(Request $request)
    {
        $messageId = $request->query->get('messageId');
        if (!$messageId) {
            throw $this->createNotFoundException();
        }

        $message = $this->messageManager->find($messageId);
        if (!$message) {
            throw $this->createNotFoundException();
        }

        if (!$this->isGranted('CAMPAIGN', $message->getCommunication()->getCampaign())) {
            throw $this->createAccessDeniedException();
        }

        return $this->render('status_communication/answers.html.twig', [
            'message' => $message,
        ]);
    }

    /**
     * @Route(
     *     name="change_answer",
     *     path="campaign/answer/{csrf}/{id}",
     *     requirements={"id" = "\d+"}
     * )
     */
    public function changeAnswerAction(Request $request, Message $message, string $csrf)
    {
        $this->validateCsrfOrThrowNotFoundException('communication', $csrf);

        if (!$this->isGranted('CAMPAIGN', $message->getCommunication()->getCampaign())) {
            throw $this->createAccessDeniedException();
        }

        $choiceEntity = null;
        $choiceId     = $request->request->get('choiceId');
        foreach ($message->getCommunication()->getChoices() as $choice) {
            if ($choice->getId() == $choiceId) {
                $choiceEntity = $choice;
            }
        }
        if (!$choiceEntity) {
            throw $this->createNotFoundException();
        }

        $this->messageManager->toggleAnswer($message, $choiceEntity);

        return new Response();
    }

    /**
     * @Route(path="campaign/{campaignId}/rename-communication/{communicationId}", name="rename")
     * @Entity("campaign", expr="repository.find(campaignId)")
     * @Entity("communicationEntity", expr="repository.find(communicationId)")
     * @IsGranted("CAMPAIGN", subject="campaign")
     */
    public function rename(Request $request, Campaign $campaign, Communication $communicationEntity): Response
    {
        $this->validateCsrfOrThrowNotFoundException('communication', $request->request->get('csrf'));

        $communication        = new CommunicationModel();
        $communication->label = $request->request->get('new_name');
        $errors               = $this->get('validator')->validate($communication, null, ['label_edition']);
        if (count($errors) > 0) {
            foreach ($errors as $error) {
                $this->addFlash('danger', $error->getMessage());
            }
        } else {
            $this->communicationManager->changeName($communicationEntity, $communication->label);
        }

        return $this->redirect($this->generateUrl('communication_index', [
            'id' => $campaign->getId(),
        ]));
    }

    /**
     * @Route("campaign/{campaign}/communication/{communication}/relaunch", name="relaunch")
     * @Security("is_granted('ROLE_ADMIN')")
     * @param Communication $communication
     * @param ProcessorInterface $processor
     * @return \Symfony\Component\HttpFoundation\RedirectResponse
     */
    public function relaunchCommunication(Campaign $campaign, Communication $communication, ProcessorInterface $processor)
    {
        $processor->process($communication);

        return $this->redirectToRoute('communication_index', ['id' => $campaign->getId()]);
    }

    private function getCommunicationFromRequest(Request $request): CommunicationModel
    {
        if ($request->request->get('campaign')) {
            // New campaign form
            $campaignModel = new \App\Form\Model\Campaign();
            $this
                ->createForm(CampaignType::class, $campaignModel)
                ->handleRequest($request);
            $communicationModel = $campaignModel->communication;
        } else {
            // Add communication form
            $communicationModel = new CommunicationModel();
            $this
                ->createForm(CommunicationType::class, $communicationModel)
                ->handleRequest($request);
        }

        return $communicationModel;
    }
}
