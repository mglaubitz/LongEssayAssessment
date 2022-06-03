<?php

namespace ILIAS\Plugin\LongEssayTask\WriterAdmin;

use ILIAS\Plugin\LongEssayTask\Data\Essay;
use ILIAS\Plugin\LongEssayTask\Data\TimeExtension;
use ILIAS\Plugin\LongEssayTask\Data\Writer;
use ILIAS\Plugin\LongEssayTask\Data\WriterHistory;

abstract class WriterListGUI
{
	/**
	 * @var Writer[]
	 */
	protected $writers = [];

	protected $user_ids = [];
	/**
	 * @var array
	 */
	protected $user_data = [];

	protected \ILIAS\UI\Factory $uiFactory;
	protected \ilCtrl $ctrl;
	protected \ilLongEssayTaskPlugin $plugin;
	protected \ILIAS\UI\Renderer $renderer;
	protected object $parent;

	public function __construct(object $parent, \ilLongEssayTaskPlugin $plugin)
	{
		global $DIC;
		$this->parent = $parent;
		$this->uiFactory = $DIC->ui()->factory();
		$this->ctrl = $DIC->ctrl();
		$this->plugin = $plugin;
		$this->renderer = $DIC->ui()->renderer();
	}

	abstract public function getContent():string;

	/**
	 * @return Writer[]
	 */
	public function getWriters(): array
	{
		return $this->writers;
	}

	/**
	 * @param Writer[] $writers
	 */
	public function setWriters(array $writers): void
	{
		$this->writers = $writers;

		foreach($writers as $writer){
			$this->user_ids[] = $writer->getUserId();
		}
	}

	/**
	 * Get Username
	 *
	 * @param $user_id
	 * @return mixed|string
	 */
	protected function getUsername($user_id){
		if(isset($this->user_data[$user_id])){
			return $this->user_data[$user_id];
		}
		return ' - ';
	}

	/**
	 * Load needed Usernames From DB
	 * @return void
	 */
	protected function loadUserData()
	{
		$back = $this->ctrl->getLinkTarget($this->parent);
		$this->user_data = \ilUserUtil::getNamePresentation(array_unique($this->user_ids), true, true, $back, true);
	}
}