<?php
/* Copyright (c) 2021 ILIAS open source, Extended GPL, see docs/LICENSE */

namespace ILIAS\Plugin\LongEssayTask\CorrectorAdmin;

use ILIAS\Plugin\LongEssayTask\BaseService;
use ILIAS\Plugin\LongEssayTask\Data\CorrectionSettings;
use ILIAS\Plugin\LongEssayTask\Data\Corrector;
use ILIAS\Plugin\LongEssayTask\Data\CorrectorAssignment;
use ILIAS\Plugin\LongEssayTask\Data\CorrectorRepository;
use ILIAS\Plugin\LongEssayTask\Data\CorrectorSummary;
use ILIAS\Plugin\LongEssayTask\Data\Essay;
use ILIAS\Plugin\LongEssayTask\Data\WriterRepository;

/**
 * Service for maintaining correctors (business logic)
 * @package ILIAS\Plugin\LongEssayTask\CorrectorAdmin
 */
class CorrectorAdminService extends BaseService
{

    public const ASSIGN_RANDOM_EQUAL = 'random_equal';

    /** @var CorrectionSettings */
    protected $settings;

    /** @var WriterRepository */
    protected $writerRepo;

    /** @var CorrectorRepository */
    protected $correctorRepo;

    /**
     * @inheritDoc
     */
    public function __construct($object)
    {
        parent::__construct($object);

        $this->settings = $this->localDI->getTaskRepo()->getCorrectionSettingsById($this->object->getId()) ??
            new CorrectionSettings($this->object->getId());

        $this->writerRepo = $this->localDI->getWriterRepo();
        $this->correctorRepo = $this->localDI->getCorrectorRepo();
    }

    /**
     * Get the Correction settings
     * @return CorrectionSettings
     */
    public function getSettings() : CorrectionSettings
    {
        return $this->settings;
    }

    /**
     * Add an ilias user as corrector to the task
     * @param $user_id
     */
    public function addUserAsCorrector($user_id)
    {
        $corrector = $this->correctorRepo->getCorrectorByUserId($user_id, $this->settings->getTaskId());
        if (!isset($corrector)) {
            $corrector = new Corrector();
            $corrector->setUserId($user_id);
            $corrector->setTaskId($this->settings->getTaskId());
            $this->correctorRepo->createCorrector($corrector);
        }
    }

    /**
     * Assign correctors to empty corrector positions for the candidates
     * @return int number of new assignments
     */
    public function assignMissingCorrectors(?string $assignMode = self::ASSIGN_RANDOM_EQUAL) : int
    {
        switch ($assignMode) {
            case self::ASSIGN_RANDOM_EQUAL:
            default:
                return $this->assignByRandomEqualMode();
        }
    }

    /**
     * Assign correctors randomly so that they get nearly equal number of corrections
     * @return int number of new assignments
     */
    protected function assignByRandomEqualMode() : int
    {
        $required = $this->settings->getRequiredCorrectors();
        if ($required <= 1) {
            return 0;
        }

        $assigned = 0;
        $writerCorrectors = [];
        $correctorWriters = [];

        // collect assignment data
        foreach ($this->correctorRepo->getCorrectorsByTaskId($this->settings->getTaskId()) as $corrector) {
            // init list of correctors with writers
            $correctorWriters[$corrector->getId()] = [];
        }
        foreach ($this->writerRepo->getWritersByTaskId($this->settings->getTaskId()) as $writer) {

            // get only writers with authorized essays
            $essay = $this->localDI->getEssayRepo()->getEssayByWriterIdAndTaskId($writer->getId(), $this->settings->getTaskId());
            if (!isset($essay) || empty($essay->getWritingAuthorized())) {
                return 0;
            }

            // init list writers with correctors
            $writerCorrectors[$writer->getId()] = [];

            foreach($this->correctorRepo->getAssignmentsByWriterId($writer->getId()) as $assignment) {
                // list the assigned corrector positions for each writer, give the corrector for each position
                $writerCorrectors[$assignment->getWriterId()][$assignment->getPosition()] = $assignment->getCorrectorId();
                // list the assigned writers for each corrector, give the corrector position per writer
                $correctorWriters[$assignment->getCorrectorId()][$assignment->getWriterId()] = $assignment->getPosition();
            }
        }

        // assign empty corrector positions
        foreach ($writerCorrectors as $writerId => $correctorsByPos) {
            for ($position = 0; $position < $required; $position++) {
                // empty corrector position
                if (!isset($correctorsByPos[$position])) {

                    // collect the candidate corrector ids for the position
                    $candidatesByCount = [];
                    foreach ($correctorWriters as $correctorId => $posByWriterId) {

                        // corrector has not yet the writer assigned
                        if (!isset($posByWriterId[$writerId])) {
                            // group the candidates by their number of existing assignments
                            $candidatesByCount[count($posByWriterId)][] = $correctorId;
                        }
                    }
                    if (!empty($candidatesByCount)) {

                        // get the candidate group with the smallest number of assignments
                        ksort($candidatesByCount);
                        reset($candidatesByCount);
                        $candidateIds = current($candidatesByCount);
                        $candidateIds = array_unique($candidateIds);

                        // get a random candidate id
                        shuffle($candidateIds);
                        $correctorId = current($candidateIds);

                        // assign the corrector to the writer
                        $assignment = new CorrectorAssignment();
                        $assignment->setCorrectorId($correctorId);
                        $assignment->setWriterId($writerId);
                        $assignment->setPosition($position);
                        $this->correctorRepo->createCorrectorAssignment($assignment);
                        $assigned++;

                        // remember the assignment for the next candidate collection
                        $correctorWriters[$correctorId][$writerId] = $position;
                        // not really needed, this fills the current empty corrector position
                        $writerCorrectors[$writerId][$position] = $correctorId;
                    }
                }
            }
        }
        return $assigned;
    }

    /**
     * Check if the correction of an essay is possible
     */
    public function isCorrectionPossible(?Essay $essay, ?CorrectorSummary $summary) : bool
    {
        if (empty($essay) || empty($essay->getWritingAuthorized())) {
            return false;
        }
        if (empty($summary) || !empty($summary->getCorrectionAuthorized())) {
            return false;
        }
        return true;
    }

    /**
     * Check if the correction for an essay needs a stitch decision
     */
    public function isStitchDecisionNeeded(?Essay $essay) : bool
    {
        if (empty($essay) || empty($essay->getWritingAuthorized())) {
            // essay is not authorized
            return false;
        }

        $numCorrected = 0;
        $minPoints = null;
        $maxPoints = null;
        foreach ($this->correctorRepo->getAssignmentsByWriterId($essay->getWriterId()) as $assignment) {
            $summary = $this->localDI->getEssayRepo()->getCorrectorSummaryByEssayIdAndCorrectorId(
                $essay->getId(), $assignment->getCorrectorId());
            if (empty($summary) || empty($summary->getCorrectionAuthorized())) {
                // one correction is not authorized
                return false;
            }
            $numCorrected++;
            $minPoints = (isset($minPoints) ? min($minPoints, $summary->getPoints()) : $summary->getPoints());
            $maxPoints = (isset($maxPoints) ? max($maxPoints, $summary->getPoints()) : $summary->getPoints());
        }

        if ($numCorrected < $this->settings->getRequiredCorrectors()) {
            // not enough correctors => not yet ready
            return false;
        }

        if (abs($maxPoints - $minPoints) <= $this->settings->getMaxAutoDistance()) {
            // distance is within limit
            return false;
        }

        return true;
    }


}