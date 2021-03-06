<?php

namespace App\Manager;

use App\Entity\Answer;
use App\Entity\GeoLocation;
use App\Entity\Message;
use App\Entity\Structure;
use App\Entity\Volunteer;
use App\Repository\VolunteerRepository;
use Doctrine\ORM\QueryBuilder;
use Symfony\Contracts\Translation\TranslatorInterface;

class VolunteerManager
{
    /**
     * @var VolunteerRepository
     */
    private $volunteerRepository;

    /**
     * @var UserManager
     */
    private $userManager;

    /**
     * @var TagManager
     */
    private $tagManager;

    /**
     * @var AnswerManager
     */
    private $answerManager;

    /**
     * @var GeoLocationManager
     */
    private $geoLocationManager;

    /**
     * @var PhoneManager
     */
    private $phoneManager;

    /**
     * @var TranslatorInterface
     */
    private $translator;

    public function __construct(VolunteerRepository $volunteerRepository,
        TagManager $tagManager,
        AnswerManager $answerManager,
        GeoLocationManager $geoLocationManager,
        PhoneManager $phoneManager,
        TranslatorInterface $translator)
    {
        $this->volunteerRepository = $volunteerRepository;
        $this->tagManager          = $tagManager;
        $this->answerManager       = $answerManager;
        $this->geoLocationManager  = $geoLocationManager;
        $this->phoneManager        = $phoneManager;
        $this->translator          = $translator;
    }

    /**
     * @required
     */
    public function setUserManager(UserManager $userManager)
    {
        $this->userManager = $userManager;
    }

    /**
     * @param int $volunteerId
     *
     * @return Volunteer|null
     */
    public function find(int $volunteerId) : ?Volunteer
    {
        return $this->volunteerRepository->find($volunteerId);
    }

    /**
     * @param string $nivol
     *
     * @return Volunteer|null
     */
    public function findOneByNivol(string $nivol) : ?Volunteer
    {
        return $this->volunteerRepository->findOneByNivol($nivol);
    }

    /**
     * @param string $phoneNumber
     *
     * @return Volunteer|null
     */
    public function findOneByPhoneNumber(string $phoneNumber) : ?Volunteer
    {
        $phone = $this->phoneManager->findOneByPhoneNumber($phoneNumber);

        return $phone ? $phone->getVolunteer() : null;
    }

    /**
     * @param string $email
     *
     * @return Volunteer|null
     */
    public function findOneByEmail(string $email) : ?Volunteer
    {
        return $this->volunteerRepository->findOneByEmail($email);
    }

    /**
     * @param Volunteer $volunteer
     */
    public function save(Volunteer $volunteer)
    {
        $this->volunteerRepository->save($volunteer);
    }

    /**
     * @param string|null $criteria
     *
     * @return Volunteer[]|array
     */
    public function searchAll(?string $criteria, int $limit)
    {
        return $this->volunteerRepository->searchAll($criteria, $limit);
    }

    public function searchForCurrentUser(?string $criteria, int $limit, bool $onlyEnabled = false)
    {
        return $this->volunteerRepository->searchForUser(
            $this->userManager->findForCurrentUser(),
            $criteria,
            $limit,
            $onlyEnabled
        );
    }

    public function searchInStructureQueryBuilder(Structure $structure,
        ?string $criteria,
        bool $onlyEnabled,
        bool $onlyUsers)
    {
        return $this->volunteerRepository->searchInStructureQueryBuilder($structure, $criteria, $onlyEnabled, $onlyUsers);
    }

    public function searchAllQueryBuilder(?string $criteria, bool $onlyEnabled, bool $onlyUsers) : QueryBuilder
    {
        return $this->volunteerRepository->searchAllWithFiltersQueryBuilder($criteria, $onlyEnabled, $onlyUsers);
    }

    public function searchForCurrentUserQueryBuilder(?string $criteria,
        bool $onlyEnabled,
        bool $onlyUsers) : QueryBuilder
    {
        return $this->volunteerRepository->searchForUserQueryBuilder(
            $this->userManager->findForCurrentUser(),
            $criteria,
            $onlyEnabled,
            $onlyUsers
        );
    }

    public function foreach(callable $callback, bool $onlyEnabled = true)
    {
        $this->volunteerRepository->foreach($callback, $onlyEnabled);
    }

    public function findIssues() : array
    {
        $volunteers = $this->volunteerRepository->getIssues(
            $this->userManager->findForCurrentUser()
        );

        $issues = [
            'phones' => 0,
            'emails' => 0,
        ];

        foreach ($volunteers as $volunteer) {
            /** @var Volunteer $volunteer */
            if (!$volunteer->getPhoneNumber()) {
                $issues['phones']++;
            }
            if (!$volunteer->getEmail()) {
                $issues['emails']++;
            }
        }

        return $issues;
    }

    /**
     * @return array
     */
    public function getIssues() : array
    {
        return $this->volunteerRepository->getIssues(
            $this->userManager->findForCurrentUser()
        );
    }

    public function synchronizeWithPegass()
    {
        $this->volunteerRepository->synchronizeWithPegass();
    }

    /**
     * @param array $nivols
     *
     * @return Volunteer[]
     */
    public function filterByNivolAndAccess(array $nivols) : array
    {
        $user = $this->userManager->findForCurrentUser();

        if ($user->isAdmin()) {
            return $this->volunteerRepository->filterByNivols($nivols);
        }

        return $this->volunteerRepository->filterByNivolsAndAccess($nivols, $user);
    }

    /**
     * @param array $ids
     *
     * @return Volunteer[]
     */
    public function filterByIdAndAccess(array $ids) : array
    {
        $user = $this->userManager->findForCurrentUser();

        if ($user->isAdmin()) {
            return $this->volunteerRepository->filterByIds($ids);
        }

        return $this->volunteerRepository->filterByIdsAndAccess($ids, $user);
    }

    public function classifyNivols(array $nivols) : array
    {
        $user = $this->userManager->findForCurrentUser();

        $reachable    = $this->volunteerRepository->filterReachableNivols($nivols, $user);
        $invalid      = $this->volunteerRepository->filterInvalidNivols($nivols);
        $disabled     = $this->volunteerRepository->filterDisabledNivols($nivols);
        $noPhone      = $this->volunteerRepository->filterNoPhoneNivols($nivols, $user);
        $phoneOptout  = $this->volunteerRepository->filterPhoneOptOutNivols($nivols, $user);
        $noEmail      = $this->volunteerRepository->filterNoEmailNivols($nivols, $user);
        $emailOptout  = $this->volunteerRepository->filterEmailOptOutNivols($nivols, $user);
        $inaccessible = array_diff($nivols, $reachable, $invalid, $disabled, $noPhone, $phoneOptout, $noEmail, $emailOptout);

        if ($user->isAdmin()) {
            $reachable    = array_merge($reachable, $inaccessible);
            $inaccessible = [];
        }

        return [
            'reachable'    => $reachable,
            'invalid'      => $invalid,
            'disabled'     => $disabled,
            'inaccessible' => $inaccessible,
            'no_phone'     => $noPhone,
            'phone_optout' => $phoneOptout,
            'no_email'     => $noEmail,
            'email_optout' => $emailOptout,
        ];
    }

    public function loadVolunteersAudience(Structure $structure, array $nivols) : array
    {
        $rows = $this->volunteerRepository->loadVolunteersAudience($structure, $nivols);

        return $this->populateDatalist($rows);
    }

    public function searchVolunteersAudience(Structure $structure, string $criteria) : array
    {
        $rows = $this->volunteerRepository->searchVolunteersAudience($structure, $criteria);

        return $this->populateDatalist($rows);
    }

    public function searchVolunteerAudienceByTags(array $tags, Structure $structure) : array
    {
        return $this->volunteerRepository->searchVolunteerAudienceByTags($tags, $structure);
    }

    public function organizeNivolsByStructures(array $structures, array $nivols) : array
    {
        $organized = [];

        $rows = $this->volunteerRepository->getNivolsAndStructures($structures, $nivols);
        foreach ($rows as $row) {
            if (!isset($organized[$row['structure_id']])) {
                $organized[$row['structure_id']] = [];
            }
            $organized[$row['structure_id']][] = $row['nivol'];
        }

        if (!count($organized)) {
            $organized[] = [];
        }

        // All other nivols were set in the "nivol" field
        $diff         = call_user_func_array('array_diff', array_merge([$nivols], array_values($organized)));
        $organized[0] = $diff;

        return $organized;
    }

    public function anonymize(Volunteer $volunteer)
    {
        foreach ($volunteer->getMessages() as $message) {
            /** @var Message $message */
            foreach ($message->getAnswers() ?? [] as $answer) {
                /** @var Answer $answer */
                if (!$answer->getByAdmin()) {
                    $answer->setRaw('');
                    $answer->setUnclear(false);
                    $answer->getChoices()->clear();
                    $this->answerManager->save($answer);
                }
            }

            if ($geo = $message->getGeoLocation()) {
                /** @var GeoLocation $geo */
                $geo->setLongitude('0.0');
                $geo->setLatitude('0.0');
                $geo->setAccuracy(0);
                $geo->setHeading(0);
                $this->geoLocationManager->save($geo);
            }
        }

        if ($user = $volunteer->getUser()) {
            $this->userManager->remove($user);
        }

        $volunteer->setFirstName('-');
        $volunteer->setLastName('-');
        $volunteer->getPhones()->clear();
        $volunteer->setEmail(null);
        $volunteer->setEnabled(false);
        $volunteer->setLocked(true);
        $volunteer->getTags()->clear();
        $volunteer->setLastPegassUpdate(new \DateTime('2000-01-01'));
        $volunteer->setReport([]);
        $volunteer->getStructures()->clear();
        $volunteer->setUser(null);
        $volunteer->setPhoneNumberOptin(true);
        $volunteer->setPhoneNumberLocked(false);
        $volunteer->setEmailOptin(true);
        $volunteer->setEmailLocked(false);

        $this->save($volunteer);
    }

    private function populateDatalist(array $rows) : array
    {
        $tags = $this->tagManager->findTagsForNivols(
            array_unique(array_column($rows, 'nivol'))
        );

        $mapped = [];
        foreach ($rows as $volunteer) {
            $volunteer['tags']           = [];
            $mapped[$volunteer['nivol']] = $volunteer;
        }

        foreach ($tags as $tag) {
            $mapped[$tag['nivol']]['tags'][] = $this->translator->trans(sprintf('tag.shortcuts.%s', $tag['label']));
        }

        foreach ($mapped as $nivol => $volunteer) {
            $mapped[$nivol]['tags'] = implode(', ', $volunteer['tags']);
        }

        return array_values($mapped);
    }
}