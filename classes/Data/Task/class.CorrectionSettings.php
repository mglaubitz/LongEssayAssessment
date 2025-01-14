<?php
/* Copyright (c) 2021 ILIAS open source, Extended GPL, see docs/LICENSE */

namespace ILIAS\Plugin\LongEssayAssessment\Data\Task;

use ILIAS\Plugin\LongEssayAssessment\Data\RecordData;

/**
 * @author Fabian Wolf <wolf@ilias.de>
 */
class CorrectionSettings extends RecordData
{
    public const ASSIGN_MODE_RANDOM_EQUAL = 'random_equal';
    
    public const CRITERIA_MODE_NONE = 'none';
    public const CRITERIA_MODE_FIXED = 'fixed';
	public const CRITERIA_MODE_CORRECTOR = 'corr';

	protected const tableName = 'xlas_corr_setting';
	protected const hasSequence = false;
	protected const keyTypes = [
		'task_id' => 'integer',
	];
	protected const otherTypes = [
		'required_correctors'=> 'integer',
		'mutual_visibility' => 'integer',
		'multi_color_highlight' => 'integer',
		'max_points' => 'integer',
		'max_auto_distance' => 'integer',
		'assign_mode' => 'text',
		'stitch_when_distance' => 'integer',
		'stitch_when_decimals' => 'integer',
        'criteria_mode' => 'text'
	];

    protected int $task_id;
    protected int $required_correctors = 2;
    protected int $mutual_visibility = 1;
    protected int $multi_color_highlight = 1;
    protected int $max_points = 0;
    protected int $max_auto_distance = 0;
    protected string $assign_mode = self::ASSIGN_MODE_RANDOM_EQUAL;
    protected int $stitch_when_distance = 1;
    protected int $stitch_when_decimals = 1;
    protected string $criteria_mode = self::CRITERIA_MODE_NONE;

	public function __construct(int $task_id)
	{
		$this->task_id = $task_id;
	}

	public static function model() {
		return new self(0);
	}


	/**
     * @return int
     */
    public function getTaskId(): int
    {
        return $this->task_id;
    }

    /**
     * @param int $task_id
     * @return CorrectionSettings
     */
    public function setTaskId(int $task_id): CorrectionSettings
    {
        $this->task_id = $task_id;
        return $this;
    }

    /**
     * @return int
     */
    public function getRequiredCorrectors(): int
    {
        return $this->required_correctors;
    }

    /**
     * @param int $required_correctors
     * @return CorrectionSettings
     */
    public function setRequiredCorrectors(int $required_correctors): CorrectionSettings
    {
        $this->required_correctors = $required_correctors;
        return $this;
    }

    /**
     * @return int
     */
    public function getMutualVisibility(): int
    {
        return $this->mutual_visibility;
    }

    /**
     * @param int $mutual_visibility
     * @return CorrectionSettings
     */
    public function setMutualVisibility(int $mutual_visibility): CorrectionSettings
    {
        $this->mutual_visibility = $mutual_visibility;
        return $this;
    }

    /**
     * @return int
     */
    public function getMultiColorHighlight(): int
    {
        return $this->multi_color_highlight;
    }

    /**
     * @param int $multi_color_highlight
     * @return CorrectionSettings
     */
    public function setMultiColorHighlight(int $multi_color_highlight): CorrectionSettings
    {
        $this->multi_color_highlight = $multi_color_highlight;
        return $this;
    }

    /**
     * @return int
     */
    public function getMaxPoints(): int
    {
        return $this->max_points;
    }

    /**
     * @param int $max_points
     * @return CorrectionSettings
     */
    public function setMaxPoints(int $max_points): CorrectionSettings
    {
        $this->max_points = $max_points;
        return $this;
    }

    /**
     * @return float
     */
    public function getMaxAutoDistance(): float
    {
        return $this->max_auto_distance;
    }

    /**
     * @param int $max_auto_distance
     * @return CorrectionSettings
     */
    public function setMaxAutoDistance(float $max_auto_distance): CorrectionSettings
    {
        $this->max_auto_distance = $max_auto_distance;
        return $this;
    }

    /**
     * @return string
     */
    public function getAssignMode(): string
    {
        return $this->assign_mode;
    }

    /**
     * @param string $assign_mode
     * @return CorrectionSettings
     */
    public function setAssignMode(string $assign_mode): CorrectionSettings
    {
        $this->assign_mode = $assign_mode;
        return $this;
    }

    /**
     * @return int
     */
    public function getStitchWhenDistance(): bool
    {
        return (bool) $this->stitch_when_distance;
    }

    /**
     * @param int $stitch_when_distance
     */
    public function setStitchWhenDistance(bool $stitch_when_distance): void
    {
        $this->stitch_when_distance = (int) $stitch_when_distance;
    }

    /**
     * @return int
     */
    public function getStitchWhenDecimals(): bool
    {
        return (bool) $this->stitch_when_decimals;
    }

    /**
     * @param int $stitch_when_decimals
     */
    public function setStitchWhenDecimals(bool $stitch_when_decimals): void
    {
        $this->stitch_when_decimals = (int) $stitch_when_decimals;
    }

    /**
     * @return string
     */
    public function getCriteriaMode(): string
    {
        return $this->criteria_mode;
    }

    /**
     * @param string $criteria_mode
     */
    public function setCriteriaMode(string $criteria_mode): void
    {
        $this->criteria_mode = $criteria_mode;
    }

}