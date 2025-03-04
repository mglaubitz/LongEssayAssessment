<?php
/* Copyright (c) 2021 ILIAS open source, Extended GPL, see docs/LICENSE */

namespace ILIAS\Plugin\LongEssayAssessment\CorrectorAdmin;

use Edutiek\LongEssayAssessmentService\Corrector\Service;
use Edutiek\LongEssayAssessmentService\Data\DocuItem;
use ILIAS\Plugin\LongEssayAssessment\BaseService;
use ILIAS\Plugin\LongEssayAssessment\Corrector\CorrectorContext;
use ILIAS\Plugin\LongEssayAssessment\Data\Task\CorrectionSettings;
use ILIAS\Plugin\LongEssayAssessment\Data\Corrector\Corrector;
use ILIAS\Plugin\LongEssayAssessment\Data\Corrector\CorrectorAssignment;
use ILIAS\Plugin\LongEssayAssessment\Data\Corrector\CorrectorRepository;
use ILIAS\Plugin\LongEssayAssessment\Data\Essay\CorrectorSummary;
use ILIAS\Plugin\LongEssayAssessment\Data\DataService;
use ILIAS\Plugin\LongEssayAssessment\Data\Essay\Essay;
use ILIAS\Plugin\LongEssayAssessment\Data\Essay\EssayRepository;
use ILIAS\Plugin\LongEssayAssessment\Data\Object\GradeLevel;
use ILIAS\Plugin\LongEssayAssessment\Data\Task\LogEntry;
use ILIAS\Plugin\LongEssayAssessment\Data\Task\TaskRepository;
use ILIAS\Plugin\LongEssayAssessment\Data\Task\TaskSettings;
use ILIAS\Plugin\LongEssayAssessment\Data\Writer\Writer;
use ILIAS\Plugin\LongEssayAssessment\Data\Writer\WriterRepository;
use ILIAS\Data\UUID\Factory as UUID;
use ilObjUser;

/**
 * Service for maintaining correctors (business logic)
 * @package ILIAS\Plugin\LongEssayAssessment\CorrectorAdmin
 */
class CorrectorAdminService extends BaseService
{
    /** @var CorrectionSettings */
    protected $settings;

    /** @var \ILIAS\Plugin\LongEssayAssessment\Data\Writer\WriterRepository */
    protected $writerRepo;

    /** @var \ILIAS\Plugin\LongEssayAssessment\Data\Corrector\CorrectorRepository */
    protected $correctorRepo;

    /** @var EssayRepository */
    protected $essayRepo;

    /** @var TaskRepository */
    protected $taskRepo;

    /** @var DataService */
    protected $dataService;

    /**
     * @inheritDoc
     */
    public function __construct(int $task_id)
    {
        parent::__construct($task_id);

        $this->settings = $this->localDI->getTaskRepo()->getCorrectionSettingsById($this->task_id) ??
            new CorrectionSettings($this->task_id);

        $this->writerRepo = $this->localDI->getWriterRepo();
        $this->correctorRepo = $this->localDI->getCorrectorRepo();
        $this->essayRepo = $this->localDI->getEssayRepo();
        $this->taskRepo = $this->localDI->getTaskRepo();
        $this->dataService = $this->localDI->getDataService($this->task_id);
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
     * Get or create a writer object for an ILIAS user
     * @param int $user_id
     * @return Corrector
     */
    public function getOrCreateCorrectorFromUserId(int $user_id) : Corrector
    {
        $corrector = $this->correctorRepo->getCorrectorByUserId($user_id, $this->settings->getTaskId());
        if (!isset($corrector)) {
            $corrector = Corrector::model();
            $corrector->setUserId($user_id);
            $corrector->setTaskId($this->settings->getTaskId());
            $this->correctorRepo->save($corrector);
        }
        return $corrector;
    }

    /**
     * Get the number of available correctors
     * @return int
     */
    public function countAvailableCorrectors() : int
    {
        return count($this->correctorRepo->getCorrectorsByTaskId($this->settings->getTaskId()));
    }

    /**
     * Get the number of missing correctors
     * @return int
     */
    public function countMissingCorrectors() : int
    {
        $required = $this->settings->getRequiredCorrectors();
        $missing = 0;
        foreach ($this->writerRepo->getWritersByTaskId($this->settings->getTaskId()) as $writer) {
            // get only writers with authorized essays without exclusion
            $essay = $this->localDI->getEssayRepo()->getEssayByWriterIdAndTaskId($writer->getId(), $this->settings->getTaskId());
            if (!isset($essay) || (empty($essay->getWritingAuthorized())) || !empty($essay->getWritingExcluded())) {
                continue;
            }
            $assigned = count($this->correctorRepo->getAssignmentsByWriterId($writer->getId()));
            $missing += max(0, $required - $assigned);
        }
        return $missing;
    }

    /**
     * Assign correctors to empty corrector positions for the candidates
     * @return int number of new assignments
     */
    public function assignMissingCorrectors() : int
    {
        switch ($this->settings->getAssignMode()) {
            case CorrectionSettings::ASSIGN_MODE_RANDOM_EQUAL:
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
        if ($required < 1) {
            return 0;
        }

        $assigned = 0;
        $writerCorrectors = [];     // writer_id => [ position => $corrector_id ]
        $correctorWriters = [];     // corrector_id => [ writer_id => position ]
        $correctorPosCount = [];    // corrector_id => [ position => count ]

        // collect assignment data
        foreach ($this->correctorRepo->getCorrectorsByTaskId($this->settings->getTaskId()) as $corrector) {
            // init list of correctors with writers
            $correctorWriters[$corrector->getId()] = [];
            for ($position = 0; $position < $required; $position++) {
                $correctorPosCount[$corrector->getId()][$position] = 0;
            }
        }
        foreach ($this->writerRepo->getWritersByTaskId($this->settings->getTaskId()) as $writer) {

            // get only writers with authorized essays
            $essay = $this->localDI->getEssayRepo()->getEssayByWriterIdAndTaskId($writer->getId(), $this->settings->getTaskId());
            if (!isset($essay) || empty($essay->getWritingAuthorized()) || !empty($essay->getWritingExcluded())) {
                continue;
            }

            // init list writers with correctors
            $writerCorrectors[$writer->getId()] = [];

            foreach($this->correctorRepo->getAssignmentsByWriterId($writer->getId()) as $assignment) {
                // list the assigned corrector positions for each writer, give the corrector for each position
                $writerCorrectors[$assignment->getWriterId()][$assignment->getPosition()] = $assignment->getCorrectorId();
                // list the assigned writers for each corrector, give the corrector position per writer
                $correctorWriters[$assignment->getCorrectorId()][$assignment->getWriterId()] = $assignment->getPosition();
                // count the assignments per position for a corrector
                $correctorPosCount[$assignment->getCorrectorId()][$assignment->getPosition()]++;
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
                            // group the candidates by their number of existing assignments for the position
                            $candidatesByCount[$correctorPosCount[$correctorId][$position]][] = $correctorId;
                        }
                    }
                    if (!empty($candidatesByCount)) {

                        // get the candidate group with the smallest number of assignments for the position
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
                        $this->correctorRepo->save($assignment);
                        $assigned++;

                        // remember the assignment for the next candidate collection
                        $correctorWriters[$correctorId][$writerId] = $position;
                        // not really needed, this fills the current empty corrector position
                        $writerCorrectors[$writerId][$position] = $correctorId;
                        // increase the assignments per position for the corrector
                        $correctorPosCount[$correctorId][$position]++;
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
        if (empty($essay) || empty($essay->getWritingAuthorized() || !empty($essay->getWritingExcluded()))) {
            return false;
        }
        if (!empty($summary) && !empty($summary->getCorrectionAuthorized())) {
            return false;
        }
        return true;
    }

    /**
     * Check if the correction for an essay needs a stitch decision
     */
    public function haveAllCorrectorsAuthorized(?Essay $essay) : bool
    {
        return count($this->getAuthorizedSummaries($essay)) >= $this->settings->getRequiredCorrectors();
    }


    /**
     * Check if the correction for an essay needs a stitch decision
     */
    public function isStitchDecisionNeeded(?Essay $essay) : bool
    {
        return empty($essay->getCorrectionFinalized()) && $this->isStitchDecisionNeededForSummaries($this->getAuthorizedSummaries($essay));
    }

    /**
     * Check if the correction for an essay needs a stitch decision
     * @param \ILIAS\Plugin\LongEssayAssessment\Data\Essay\CorrectorSummary[] $summaries
     */
    protected function isStitchDecisionNeededForSummaries(array $summaries) : bool
    {
        if (count($summaries) < $this->settings->getRequiredCorrectors()) {
            // not enough correctors authorized => not yet ready
            return false;
        }

        $minPoints = null;
        $maxPoints = null;
        foreach ($summaries as $summary) {
            $minPoints = (isset($minPoints) ? min($minPoints, $summary->getPoints()) : $summary->getPoints());
            $maxPoints = (isset($maxPoints) ? max($maxPoints, $summary->getPoints()) : $summary->getPoints());
        }

        if ($this->settings->getStitchWhenDistance()) {
            if (abs($maxPoints - $minPoints) > $this->settings->getMaxAutoDistance()) {
                // distance is within limit
                return true;
            }
        }

        if ($this->settings->getStitchWhenDecimals()) {
            $average = $this->getAveragePointsOfSummaries($summaries);
            if ($average === null) {
                // one corrector hasn't stored points (should not happen)
                return true;
            }
            if (floor($average) < $average) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the average Points of the correction summaries
     * @param CorrectorSummary[] $summaries
     */
    public function getAveragePointsOfSummaries(array $summaries) : ?float
    {
        $countOfPoints = 0;
        $sumOfPoints = null;
        foreach ($summaries as $summary) {
            if ($summary->getPoints() !== null) {
                $countOfPoints++;
                $sumOfPoints += $summary->getPoints();
            }
        }
        if ($countOfPoints > 0) {
            return $sumOfPoints / $countOfPoints;
        }
        return null;
    }

    /**
     * Get all correction summaries saved for an essay
     * @param \ILIAS\Plugin\LongEssayAssessment\Data\Essay\Essay|null $essay
     * @return \ILIAS\Plugin\LongEssayAssessment\Data\Essay\CorrectorSummary[]
     */
    public function getAuthorizedSummaries(?Essay $essay) : array
    {
        if (empty($essay) || empty($essay->getWritingAuthorized())) {
            // essay is not authorized
            return [];
        }

        $summaries = [];
        foreach ($this->correctorRepo->getAssignmentsByWriterId($essay->getWriterId()) as $assignment) {
            $summary = $this->localDI->getEssayRepo()->getCorrectorSummaryByEssayIdAndCorrectorId(
                $essay->getId(), $assignment->getCorrectorId());
            if (!empty($summary) && !empty($summary->getCorrectionAuthorized())) {
                $summaries[] = $summary;
            }
        }
        return $summaries;
    }


    /**
     * Get the resulting grade level for certain points
     * @param float $points
     * @return GradeLevel|null
     */
    protected function getGradeLevelForPoints(float $points) : ?GradeLevel
    {
        $objectRepo = $this->localDI->getObjectRepo();

        $level = null;
        $last_points = 0;
        foreach ($objectRepo->getGradeLevelsByObjectId($this->task_id) as $levelCandidate) {
            if ($levelCandidate->getMinPoints() <= $points
                && $levelCandidate->getMinPoints() >= $last_points
            ) {
                $level = $levelCandidate;
                $last_points = $level->getMinPoints();
            }
        }
        return $level;
    }

    /**
     * Try the finalisation of a correction
     */
    public function tryFinalisation(Essay $essay, int $user_id) : bool
    {
        $summaries = $this->getAuthorizedSummaries($essay);
        if (count($summaries) < $this->getSettings()->getRequiredCorrectors()) {
            return false;
        }

        if (!$this->isStitchDecisionNeededForSummaries($summaries)) {
            $average = $this->getAveragePointsOfSummaries($summaries);
            if ($average !== null) {
                if (!empty($level = $this->getGradeLevelForPoints($average))) {
                    $essay->setFinalPoints($average);
                    $essay->setFinalGradeLevelId($level->getId());
                    $essay->setCorrectionFinalized($this->dataService->unixTimeToDb(time()));
                    $essay->setCorrectionFinalizedBy($user_id);

                    $essayRepo = $this->localDI->getEssayRepo();
                    $essayRepo->save($essay);

                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Create an export file for the corrections
     * @param \ilObjLongEssayAssessment $object
     * @return string   file path of the export
     */
    public function createCorrectionsExport(\ilObjLongEssayAssessment $object) : string
    {
        $storage = $this->dic->filesystem()->temp();
        $basedir = ILIAS_DATA_DIR . '/' . CLIENT_ID . '/temp';
        $tempdir = 'xlas/'. (new UUID)->uuid4AsString();
        $zipdir = $tempdir . '/' . \ilUtil::getASCIIFilename($object->getTitle());
        $storage->createDir($zipdir);

        $repoTask = $this->taskRepo->getTaskSettingsById($object->getId());
        foreach ($this->essayRepo->getEssaysByTaskId($repoTask->getTaskId()) as $repoEssay) {
            $repoWriter = $this->writerRepo->getWriterById($repoEssay->getWriterId());

            $filename = \ilUtil::getASCIIFilename(
                \ilObjUser::_lookupFullname($repoWriter->getUserId()) . ' (' . \ilObjUser::_lookupLogin($repoWriter->getUserId()) . ')') . '.pdf';

            $storage->write($zipdir . '/'. $filename, $this->getCorrectionAsPdf($object, $repoWriter));
        }

        $zipfile = $basedir . '/' . $tempdir . '/' . \ilUtil::getASCIIFilename($object->getTitle()) . '.zip';
        \ilUtil::zip($basedir . '/' . $zipdir, $zipfile);

        $storage->deleteDir($zipdir);
        return $zipfile;

        // check if that can be used without abolute path
        // then also the tempdir can be deleted
        //$delivery = new \ilFileDelivery()
    }

    /**
     * Get the correction of an essay as PDF string
     * if a repo corrector is given as parameter, then only this correction is included,
     *      not the original writer text and not other correctors
     */
    public function getCorrectionAsPdf(\ilObjLongEssayAssessment $object, Writer $repoWriter, ?Corrector $repoCorrector = null) : string
    {
        $context = new CorrectorContext();
        $context->init((string) $this->dic->user()->getId(), (string) $object->getRefId());

        $writingTask = $context->getWritingTaskByWriterId($repoWriter->getId());
        $writtenEssay = $context->getEssayOfItem((string) $repoWriter->getId());

        $correctionSummaries = [];
        $correctionComments = [];
        foreach ($this->correctorRepo->getAssignmentsByWriterId($repoWriter->getId()) as $assignment) {
            if (!isset($repoCorrector) || $assignment->getCorrectorId() == $repoCorrector->getId()) {
                if (!empty($summary = $context->getCorrectionSummary((string) $repoWriter->getId(), (string) $assignment->getCorrectorId()))) {
                    $correctionSummaries[] = $summary;
                }
                $correctionComments = array_merge($correctionComments,
                    $context->getCorrectionComments((string) $repoWriter->getId(), (string) $assignment->getCorrectorId()));
            }
        }

        $item = new DocuItem(
            (string) $repoWriter->getId(),
            $writingTask,
            $writtenEssay,
            $correctionSummaries,
            $correctionComments
        );

        $service = new Service($context);
        return $service->getCorrectionAsPdf($item, $repoCorrector ? (string) $repoCorrector->getId() : null);
    }



    public function createResultsExport() : string
    {
        $csv = new \ilCSVWriter();
        $csv->setSeparator(';');

        $csv->addColumn($this->lng->txt('login'));
        $csv->addColumn($this->lng->txt('firstname'));
        $csv->addColumn($this->lng->txt('lastname'));
        $csv->addColumn($this->lng->txt('matriculation'));
        $csv->addColumn($this->plugin->txt('essay_status'));
        $csv->addColumn($this->plugin->txt('writing_last_save'));
        $csv->addColumn($this->plugin->txt('correction_status'));
        $csv->addColumn($this->plugin->txt('points'));
        $csv->addColumn($this->plugin->txt('grade_level'));
        $csv->addColumn($this->plugin->txt('grade_level_code'));
        $csv->addColumn($this->plugin->txt('passed'));

        $repoTask = $this->taskRepo->getTaskSettingsById($this->task_id);
        foreach ($this->essayRepo->getEssaysByTaskId($repoTask->getTaskId()) as $repoEssay) {
            $repoWriter = $this->writerRepo->getWriterById($repoEssay->getWriterId());
            $user = new ilObjUser($repoWriter->getUserId());
            $csv->addRow();
            $csv->addColumn($user->getLogin());
            $csv->addColumn($user->getFirstname());
            $csv->addColumn($user->getLastname());
            $csv->addColumn($user->getMatriculation());
            if (!empty($repoEssay->getWritingAuthorized())) {
                $csv->addColumn($this->plugin->txt('writing_status_authorized'));
            }
            elseif (!empty($repoEssay->getEditStarted())) {
                $csv->addColumn($this->plugin->txt('writing_status_not_authorized'));
            }
            else {
                $csv->addColumn($this->plugin->txt('writing_status_not_written'));
            }
            $csv->addColumn($repoEssay->getEditEnded());
            if (empty($repoEssay->getCorrectionFinalized())) {
                $csv->addColumn($this->plugin->txt('correction_status_open'));
                $csv->addColumn(null);
                $csv->addColumn(null);
                $csv->addColumn(null);
                $csv->addColumn(null);
            }
            elseif (empty($repoEssay->getWritingAuthorized())) {
                $csv->addColumn($this->plugin->txt('correction_status_not_possible'));
                $csv->addColumn(null);
                $csv->addColumn(null);
                $csv->addColumn(null);
                $csv->addColumn(null);
            }
            else {
                $csv->addColumn($this->plugin->txt('correction_status_finished'));
                $csv->addColumn($repoEssay->getFinalPoints());
                if (!empty($level = $this->localDI->getObjectRepo()->getGradeLevelById((int) $repoEssay->getFinalGradeLevelId()))) {
                    $csv->addColumn($level->getGrade());
                    $csv->addColumn($level->getCode());
                    $csv->addColumn($level->isPassed());
                }
            }
        }

        $storage = $this->dic->filesystem()->temp();
        $basedir = ILIAS_DATA_DIR . '/' . CLIENT_ID . '/temp';
        $file = 'xlas/'. (new UUID)->uuid4AsString() . '.csv';
        $storage->write($file, $csv->getCSVString());

        return $basedir . '/' . $file;
    }

	/**
	 * Sorts Assoc Array bei Position and pseudonym
	 * Prio 1 sort by Position in $items[]["position"]
	 * Prio 2 sort by Pseudonym Name in $items[]["pseudonym"]
	 *
	 * @param array $items
	 * @return void
	 */
	public function sortCorrectionsArray(array &$items){
		usort($items, function(array $item_a, array $item_b){
			if($item_a["position"] == $item_b["position"]){
				return strtolower($item_a["pseudonym"]) <=> strtolower($item_b["pseudonym"]);
			}
			return $item_a["position"] <=> $item_b["position"];
		});
	}

	/**
	 * @param int $user_id
	 * @param array $array
	 * @return array
	 */
	public function filterCorrections(int $user_id, array $array): array
	{
		$position_filter = $this->dataService->getCorrectorPositionFilter($user_id);
		$status_filter = $this->dataService->getCorrectionStatusFilter($user_id);

		return array_filter($array, function (array $item) use($position_filter, $status_filter){
			$status_ok = $status_filter == DataService::ALL || $status_filter == $item["correction_status"];
			$position_ok = $position_filter == DataService::ALL || $position_filter == $item["position"] + 1;
			return $status_ok && $position_ok;
		});
	}

    public function removeAuthorizations(Writer $writer) : bool
    {
        global $DIC;

        if (empty($essay = $this->essayRepo->getEssayByWriterIdAndTaskId($writer->getId(), $writer->getTaskId()))) {
            return false;
        }

        // remove finalized status
        if (!empty($essay->getCorrectionFinalized())) {
            $essay->setCorrectionFinalized(null);
            $essay->setCorrectionFinalizedBy(null);
            $this->essayRepo->save($essay);
        }

        // remove authorizations
        foreach ($this->getAuthorizedSummaries($essay) as $summary) {
            $summary->setCorrectionAuthorized(null);
            $summary->setCorrectionAuthorizedBy(null);
            $this->essayRepo->save($summary);
        }

        // log the actions
        $description = \ilLanguage::_lookupEntry(
            $this->lng->getDefaultLanguage(),
            $this->plugin->getPrefix(),
            $this->plugin->getPrefix() . "_remove_authorization_log"
        );

        $datetime = new \ilDateTime(time(), IL_CAL_UNIX);
        $names = \ilUserUtil::getNamePresentation([$writer->getUserId(), $DIC->user()->getId()], false, false, "", true);

        $log_entry = new LogEntry();
        $log_entry->setEntry(sprintf($description, $names[$writer->getUserId()] ?? "unknown", $names[$DIC->user()->getId()] ?? "unknown"))
            ->setTaskId($essay->getTaskId())
            ->setTimestamp($datetime->get(IL_CAL_DATETIME))
            ->setCategory(LogEntry::CATEGORY_AUTHORIZE);

        $this->taskRepo->save($log_entry);

        return true;
    }

	public function authorizeCorrection(CorrectorSummary $summary, int $user_id)
	{
		$summary->setCorrectionAuthorized($this->dataService->unixTimeToDb(time()));
		$summary->setCorrectionAuthorizedBy($user_id);
		$this->localDI->getEssayRepo()->save($summary);
	}

    public function removeSingleAuthorization(Writer $writer, Corrector $corrector) : bool
    {
        if (empty($essay = $this->essayRepo->getEssayByWriterIdAndTaskId($writer->getId(), $writer->getTaskId()))) {
            return false;
        }
        if (empty($summary = $this->localDI->getEssayRepo()->getCorrectorSummaryByEssayIdAndCorrectorId($essay->getId(), $corrector->getId()))) {
            return false;
        }

        // don't remove a singe authorization from a finalized correction
        if (!empty($essay->getCorrectionFinalized())) {
            return false;
        }

        $summary->setCorrectionAuthorized(null);
        $summary->setCorrectionAuthorizedBy(null);
        $this->essayRepo->save($summary);

        // log the actions
        $description = \ilLanguage::_lookupEntry(
            $this->lng->getDefaultLanguage(),
            $this->plugin->getPrefix(),
            $this->plugin->getPrefix() . "_remove_own_authorization_log"
        );

        $datetime = new \ilDateTime(time(), IL_CAL_UNIX);
        $names = \ilUserUtil::getNamePresentation([$writer->getUserId(), $corrector->getUserId()], false, false, "", true);

        $log_entry = new LogEntry();
        $log_entry->setEntry(sprintf($description, $names[$writer->getUserId()] ?? "unknown", $names[$corrector->getUserId()] ?? "unknown"))
            ->setTaskId($essay->getTaskId())
            ->setTimestamp($datetime->get(IL_CAL_DATETIME))
            ->setCategory(LogEntry::CATEGORY_AUTHORIZE);

        $this->taskRepo->save($log_entry);

        return true;
    }

	const BLANK_CORRECTOR_ASSIGNMENT = -1;
	const UNCHANGED_CORRECTOR_ASSIGNMENT = -2;

	/**
	 * Reassigns a couple of correctors to multiple writer
	 * - first and second corrector cannot be the same -> invalid
	 * - already authorized corrections are not changed -> unchanged
	 * - if both assignments are untouched -> unchanged
	 * - if one assignment changes -> changed
	 * - existing correction summaries and comments are moved to the new corrector -> changed
	 * - if the assignment of an existing correction is removed the summaries and comments are removed too!
	 * - criterion points are removed if an existing correction is changed or removed because they can be individual
	 *   and not reused by the new assigned corrector
	 *
	 * @param int $first_corrector
	 * @param int $second_corrector
	 * @param int[] $writer_ids
	 * @param bool $dry_run
	 * @return array[]
	 */
	public function assignMultipleCorrector(int $first_corrector,
											int $second_corrector,
											array $writer_ids,
											$dry_run = false): array
	{
		$assignments = [];
		foreach($this->correctorRepo->getAssignmentsByTaskId($this->task_id) as $assignment){
			$assignments[$assignment->getWriterId()][$assignment->getPosition()] = $assignment;
		}
		$summaries = $this->essayRepo->getCorrectorSummariesByTaskIdAndWriterIds($this->task_id, $writer_ids);

		$result = ["changed" => [], "unchanged" => [], "invalid" => []];

		foreach ($writer_ids as $writer_id){
			$first_assignment = $assignments[$writer_id][0] ?? null;
			$second_assignment = $assignments[$writer_id][1] ?? null;
			$old_first_corrector = $first_assignment !== null
				? $first_assignment->getCorrectorId()
				: null;
			$old_second_corrector = $second_assignment !== null ?
				$second_assignment->getCorrectorId() : null;

			$first_summary =  $first_assignment !== null ?
				($summaries[$writer_id][$first_assignment->getCorrectorId()] ?? null) : null;
			$second_summary = $second_assignment !== null ?
				($summaries[$writer_id][$second_assignment->getCorrectorId()] ?? null) : null;

			$first_unchanged = $this->assign($writer_id, $first_corrector, $first_assignment, $first_summary, 0); // assignment is changed by reference
			$second_unchanged = $this->assign($writer_id, $second_corrector, $second_assignment, $second_summary, 1); // assignment is changed by reference

			// Do nothing if both are unchanged
			if($first_unchanged && $second_unchanged){
				$result["unchanged"][] = $writer_id;
			}elseif($first_assignment !== null
				&& $second_assignment !== null
				&& $first_assignment->getCorrectorId() == $second_assignment->getCorrectorId()
			) {// Do not proceed if first and second position is the same
				$result["invalid"][] = $writer_id;
			}else{

				$result["changed"][] = $writer_id;
				if(!$dry_run){// Stop here if it's a dry run

					if($old_first_corrector !== null && $first_assignment !== null && $first_summary !== null){
						// Move all comments and summaries of first correction to new corrector if they changed,
						// criterium points are individual and are removed
						$this->moveCorrection($old_first_corrector, $first_assignment->getCorrectorId(), $first_summary->getEssayId());
					}

					if($old_second_corrector !== null && $second_assignment !== null && $second_summary !== null){
						// Move all comments and summaries of second correction to new corrector if they changed,
						// criterium points are individual and are removed
						$this->moveCorrection($old_second_corrector,
							$second_assignment->getCorrectorId(), $second_summary->getEssayId());
					}

					if($first_assignment === null && $old_first_corrector !== null && $first_summary !== null){
						// if the first assignment is removed, also its comments and summary are removed
						$this->deleteCorrection($old_first_corrector, $first_summary->getEssayId());
					}

					if($second_assignment === null && $old_second_corrector !== null  && $second_summary !== null){
						// if the second assignment is removed, also its comments and summary are removed
						$this->deleteCorrection($old_second_corrector, $second_summary->getEssayId());
					}

					// If something changed remove old assignments
					$this->correctorRepo->deleteCorrectorAssignmentByWriter($writer_id);

					if($first_assignment !== null){
						$this->correctorRepo->save($first_assignment);
					}

					if($second_assignment !== null){
						$this->correctorRepo->save($second_assignment);
					}
				}
			}

		}
		return $result;
	}

	private function moveCorrection(int $from_corrector, int $to_corrector, int $essay_id){
		if($from_corrector === $to_corrector)
			return;//Prevent removal of criterion points and useless queries if nothing has changed
		$this->essayRepo->moveCorrectorSummaries($from_corrector, $to_corrector, $essay_id);
		$this->essayRepo->deleteCriterionPointsByCorrectorIdAndEssayId($from_corrector, $essay_id);
		$this->essayRepo->moveCorrectorComments($from_corrector, $to_corrector, $essay_id);
	}

	private function deleteCorrection(int $corrector, int $essay_id){
		$this->essayRepo->deleteCorrectorSummaryByCorrectorIdAndEssayId($corrector, $essay_id);
		$this->essayRepo->deleteCorrectorCommentByCorrectorIdAndEssayId($corrector, $essay_id);
	}

	private function assign(int $writer_id, int $corrector, ?CorrectorAssignment &$assignment, ?CorrectorSummary $summary, int $position) : bool
	{
		$unchanged = true;
		$authorized = isset($summary) && $summary->getCorrectionAuthorized() !== null;

		if( $corrector > -1) {// corrector is real and not removed or keep unchanged
			if ($assignment == null) { // if assignment is missing create a new
				$assignment = CorrectorAssignment::model()
					->setWriterId($writer_id)
					->setCorrectorId($corrector)
					->setPosition($position);
				$unchanged = false;
			}elseif ($assignment->getCorrectorId() != $corrector && !$authorized) { // if corrector is changed assign new
				$assignment = clone $assignment; // cloning is needed to prevent the usage of cached objects
				$assignment->setCorrectorId($corrector);
				$unchanged = false;
			}
		}
		if($corrector == self::BLANK_CORRECTOR_ASSIGNMENT && !$authorized){// corrector assignment is actively removed
			$assignment = null;
			$unchanged = false;
		}
		return $unchanged;
	}

	public function canRemoveCorrectionAuthorize(Essay $essay, ?CorrectorSummary $summary) : bool
	{
		return empty($essay->getCorrectionFinalized()) && isset($summary) && !empty($summary->getCorrectionAuthorized());
	}

	public function canAuthorizeCorrection(Essay $essay, ?CorrectorSummary $summary) : bool
	{
		return !empty($essay->getWritingAuthorized()) &&
			isset($summary) &&
			empty($summary->getCorrectionAuthorized());
	}

    /**
     * @param CorrectorSummary[]|Essay[] $grading_objects
     * @return array
     *
     */
    public function gradeStatistics(array $grading_objects): array
    {
        $sum = 0;
        $count_authorized = 0;
        $count_by_level = [];
        $count_passed = 0;
        $count_not_attended = null;
        $count_all = 0;
        $passed_levels = [];

        foreach($this->localDI->getObjectRepo()->getGradeLevelsByObjectId($this->task_id) as $level)
        {
            if($level->isPassed())
                $passed_levels[] = $level->getId();

            $count_by_level[$level->getId()] = 0;
        }

        foreach($grading_objects as $grading_object){

            switch(true) {
                case ($grading_object instanceof Essay):
                    $points = $grading_object->getFinalPoints();
                    $grade = $grading_object->getFinalGradeLevelId();
                    $authorized_and_ok = $grading_object->getCorrectionFinalized() && $points !== null && $grade !== null;
                    if($count_not_attended === null) $count_not_attended = 0; // if one essay is present, there could be not attended
                    if(!$grading_object->getEditEnded() === null) $count_not_attended++; else $count_all++;
                    break;
                case ($grading_object instanceof CorrectorSummary):
                    $points = $grading_object->getPoints();
                    $grade = $grading_object->getGradeLevelId();
                    $authorized_and_ok = $grading_object->getCorrectionAuthorized() && $points !== null && $grade !== null;
                    $count_all++;
                    break;
                default:
                    throw new \ValueError("Could not evaluate object of type " . get_class($grading_object) . " for grade statistics.");
            }
            if ($authorized_and_ok) {
                $sum += $points;
                $count_authorized++;
                $count_by_level[$grade] = ($count_by_level[$grade] ?? 0) + 1;
                if (in_array($grade, $passed_levels)) {
                    $count_passed++;
                }
            }
        }

        return [
            self::STATISTIC_COUNT_BY_LEVEL => $count_by_level,
            self::STATISTIC_COUNT => $count_all,
            self::STATISTIC_FINAL => $count_authorized,
            self::STATISTIC_TODO => $count_all - $count_authorized,
            self::STATISTIC_PASSED => $count_passed,
            self::STATISTIC_NOT_PASSED => $count_authorized - $count_passed,
            self::STATISTIC_NOT_PASSED_QUOTA => $count_authorized > 0 ? ($count_passed / $count_authorized) - 1 : null,
            self::STATISTIC_AVERAGE => $count_authorized > 0 ? ($sum / $count_authorized) : null,
            self::STATISTIC_NOT_ATTENDED => $count_not_attended
        ];
    }
    CONST STATISTIC_COUNT_BY_LEVEL = 0;
    CONST STATISTIC_COUNT = 1;
    CONST STATISTIC_FINAL = 2;
    CONST STATISTIC_TODO = 3;
    CONST STATISTIC_PASSED = 4;
    CONST STATISTIC_NOT_PASSED = 5;
    CONST STATISTIC_AVERAGE = 6;
    CONST STATISTIC_NOT_PASSED_QUOTA = 7;
    CONST STATISTIC_NOT_ATTENDED = 8;
}
