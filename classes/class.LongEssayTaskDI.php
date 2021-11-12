<?php

namespace ILIAS\Plugin\LongEssayTask;

use ILIAS\Plugin\LongEssayTask\Data\AlertDatabaseRepository;
use ILIAS\Plugin\LongEssayTask\Data\AlertRepository;
use ILIAS\Plugin\LongEssayTask\Data\CorrectionSettingsDatabaseRepository;
use ILIAS\Plugin\LongEssayTask\Data\CorrectionSettingsRepository;
use ILIAS\Plugin\LongEssayTask\Data\WriterCommentDatabaseRepository;
use ILIAS\Plugin\LongEssayTask\Data\WriterCommentRepository;
use ILIAS\Plugin\LongEssayTask\Data\WriterHistoryDatabaseRepository;
use ILIAS\Plugin\LongEssayTask\Data\WriterHistoryRepository;
use ILIAS\Plugin\LongEssayTask\Data\WriterNoticeDatabaseRepository;
use ILIAS\Plugin\LongEssayTask\Data\WriterNoticeRepository;
use ILIAS\Plugin\LongEssayTask\Data\EssayRepository;
use ILIAS\Plugin\LongEssayTask\Data\EssayDatabaseRepository;

/**
 * @author Fabian Wolf <wolf@ilias.de>
 */
class LongEssayTaskDI
{
	protected EssayRepository $essay;
	protected AlertRepository $alert;
	protected CorrectionSettingsRepository $correction_settings;
	protected WriterNoticeRepository $writer_notice;
	protected WriterCommentRepository $writer_comment;
	protected WriterHistoryRepository $writer_history;

	public function getEssayRepo(): EssayRepository
	{
		if ($this->essay === null)
		{
			$this->essay = new EssayDatabaseRepository();
		}

		return $this->essay;
	}

	public function getAlertRepo(): AlertRepository
	{
		if ($this->alert === null)
		{
			$this->alert = new AlertDatabaseRepository();
		}

		return $this->alert;
	}

	public function getCorrectionSettingsRepo(): CorrectionSettingsRepository
	{
		if ($this->correction_settings === null)
		{
			$this->correction_settings = new CorrectionSettingsDatabaseRepository();
		}

		return $this->correction_settings;
	}

	public function getWriterNoticeRepo(): WriterNoticeRepository
	{
		if ($this->writer_notice === null)
		{
			$this->writer_notice = new WriterNoticeDatabaseRepository();
		}

		return $this->writer_notice;
	}

	public function getWriterCommentRepo(): WriterCommentRepository
	{
		if ($this->writer_comment === null)
		{
			$this->writer_comment = new WriterCommentDatabaseRepository();
		}

		return $this->writer_comment;
	}

	public function getWriterHistoryRepo(): WriterHistoryRepository
	{
		if ($this->writer_history === null)
		{
			$this->writer_history = new WriterHistoryDatabaseRepository();
		}

		return $this->writer_history;
	}
}