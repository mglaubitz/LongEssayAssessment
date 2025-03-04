<?php
/* Copyright (c) 2021 ILIAS open source, Extended GPL, see docs/LICENSE */

namespace ILIAS\Plugin\LongEssayAssessment\Data\Essay;

use ILIAS\Plugin\LongEssayAssessment\Data\RecordData;

/**
 * @author Fabian Wolf <wolf@ilias.de>
 */
class WriterComment extends RecordData
{

	protected const tableName = 'xlas_writer_comment';
	protected const hasSequence = true;
	protected const keyTypes = [
		'id' => 'integer',
	];
	protected const otherTypes = [
		'task_id' => 'integer',
		'comment' => 'text',
		'start_position' => 'integer',
		'end_position' => 'integer'
	];

    protected int $id = 0;
    protected int $task_id = 0;
    protected ?string $comment = null;
    protected int $start_position = 0;
    protected int $end_position = 0;

	public static function model() {
		return new self();
	}

    /**
     * @return int
     */
    public function getId(): int
    {
        return $this->id;
    }

    /**
     * @param int $id
     * @return WriterComment
     */
    public function setId(int $id): WriterComment
    {
        $this->id = $id;
        return $this;
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
     * @return WriterComment
     */
    public function setTaskId(int $task_id): WriterComment
    {
        $this->task_id = $task_id;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getComment(): ?string
    {
        return $this->comment;
    }

    /**
     * @param string|null $comment
     * @return WriterComment
     */
    public function setComment(?string $comment): WriterComment
    {
        $this->comment = $comment;
        return $this;
    }

    /**
     * @return int
     */
    public function getStartPosition(): int
    {
        return $this->start_position;
    }

    /**
     * @param int $start_position
     * @return WriterComment
     */
    public function setStartPosition(int $start_position): WriterComment
    {
        $this->start_position = $start_position;
        return $this;
    }

    /**
     * @return int
     */
    public function getEndPosition(): int
    {
        return $this->end_position;
    }

    /**
     * @param int $end_position
     * @return WriterComment
     */
    public function setEndPosition(int $end_position): WriterComment
    {
        $this->end_position = $end_position;
        return $this;
    }


}