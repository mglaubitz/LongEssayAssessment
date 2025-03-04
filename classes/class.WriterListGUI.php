<?php

namespace ILIAS\Plugin\LongEssayAssessment\WriterAdmin;

use Exception;
use ILIAS\Plugin\LongEssayAssessment\Data\Essay\Essay;
use ILIAS\Plugin\LongEssayAssessment\Data\Writer\Writer;
use ILIAS\Plugin\LongEssayAssessment\LongEssayAssessmentDI;
use ILIAS\UI\Component\Symbol\Icon\Icon;

abstract class WriterListGUI
{
    /**
     * @var Essay[]
     */
    protected $essays = [];

    /**
	 * @var \ILIAS\Plugin\LongEssayAssessment\Data\Writer\Writer[]
	 */
	protected $writers = [];

	protected $user_ids = [];
	/**
	 * @var array
	 */
	protected $user_data = [];

	/**
	 * @var bool
	 */
	protected $user_data_loaded = false;

	protected \ILIAS\UI\Factory $uiFactory;
	protected \ilCtrl $ctrl;
	protected \ilLongEssayAssessmentPlugin $plugin;
	protected \ILIAS\UI\Renderer $renderer;
	protected object $parent;
	protected string $parent_cmd;

    /** @var LongEssayAssessmentDI  */
    protected $localDI;


    public function __construct(object $parent, string $parent_cmd, \ilLongEssayAssessmentPlugin $plugin)
	{
		global $DIC;
		$this->parent = $parent;
		$this->parent_cmd = $parent_cmd;
		$this->uiFactory = $DIC->ui()->factory();
		$this->ctrl = $DIC->ctrl();
		$this->plugin = $plugin;
		$this->renderer = $DIC->ui()->renderer();
        $this->localDI = LongEssayAssessmentDI::getInstance();
	}

	abstract public function getContent():string;


    /**
     * @return Essay[]
     */
    public function getEssays(): array
    {
        return $this->essays;
    }

    /**
     * @param Essay[] $essays
     */
    public function setEssays(array $essays): void
    {
        foreach ($essays as $essay){
            $this->essays[$essay->getWriterId()] = $essay;
            $this->user_ids[] = $essay->getCorrectionFinalizedBy();
            $this->user_ids[] = $essay->getWritingAuthorizedBy();
            $this->user_ids[] = $essay->getWritingExcludedBy();
        }
    }

    /**
	 * @return \ILIAS\Plugin\LongEssayAssessment\Data\Writer\Writer[]
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
	protected function getUsername($user_id, $strip_img = false){
		if(!$this->user_data_loaded){
			throw new Exception("getUsername was called without loading usernames.");
		}

		if(isset($this->user_data[$user_id])){
			if($strip_img){
				return strip_tags($this->user_data[$user_id], ["a"]);
			}else{
				return $this->user_data[$user_id];
			}
		}
        elseif (!empty($fullname = \ilObjUser::_lookupFullname($user_id))) {
            return $fullname;
        }

		return ' - ';
	}

	/**
	 * Get Writer name
	 *
	 * @param \ILIAS\Plugin\LongEssayAssessment\Data\Writer\Writer $writer
	 * @return string
	 */
	protected function getWriterName(Writer $writer, $strip_img = false): string
	{
		return $this->getUsername($writer->getUserId(), $strip_img);
	}


	/**
	 * Get Writer Profile Picture
	 *
	 * @param \ILIAS\Plugin\LongEssayAssessment\Data\Writer\Writer $writer
	 * @return Icon
	 * @throws Exception
	 */
	protected function getWriterIcon(Writer $writer): Icon
	{
		return $this->getUserIcon($writer->getUserId());
	}

	/**
	 * Get User Profile Picture
	 *
	 * @param int $user_id
	 * @return Icon
	 */
	protected function getUserIcon(int $user_id): Icon
	{
		$name = $this->getUsername($user_id, false);
		preg_match('/src="(.+?)"/', $name, $matches);
        $src = $matches[1] ?? "";
		$label = $this->plugin->txt("icon_label") . " " . strip_tags($name);

        return $src !== ""
            ? $this->uiFactory->symbol()->icon()->custom($src, $label, "medium")
            : $this->uiFactory->symbol()->icon()->standard("usr", "", "medium");
	}

	/**
	 * Load needed Usernames From DB
	 * @return void
	 */
	protected function loadUserData()
	{
		$back = $this->ctrl->getLinkTarget($this->parent);
		$this->user_data = \ilUserUtil::getNamePresentation(array_unique($this->user_ids), true, true, $back, true);
		$this->user_data_loaded = true;
	}

	/**
	 * @param \ILIAS\Plugin\LongEssayAssessment\Data\Writer\Writer $writer
	 * @return string
	 */
	protected function getWriterAnchor(Writer $writer): string
	{
		$user_id = $writer->getUserId();
		$writer_id = $writer->getId();
		return "<blankanchor id='writer_$writer_id'><blankanchor id='user_$user_id'>";
	}

	/**
	 * @param callable|null $custom_sort Custom sortation callable. Equal writer will be sorted by name.
	 * @return void
	 */
	protected function sortWriter(callable $custom_sort = null){
		$this->sortWriterOrCorrector($this->writers, $custom_sort);
	}

    protected function getExportStepsTarget(Writer $writer) {
        $this->ctrl->setParameter($this->parent,"writer_id", $writer->getId());
        return $this->ctrl->getLinkTarget($this->parent, "exportSteps");
    }

    /**
	 * @param callable|null $custom_sort Custom sortation callable. Equal writer will be sorted by name.
	 * @return void
	 */
	protected function sortWriterOrCorrector(array &$target_array, callable $custom_sort = null){
		if(!$this->user_data_loaded){
			throw new Exception("sortWriterOrCorrector was called without loading usernames.");
		}

		$names = [];
		foreach ($this->user_data as $usr_id => $name){
			$names[$usr_id] = strip_tags($name);
		}

		$by_name = function($a, $b) use($names) {
			$name_a = array_key_exists($a->getUserId(), $names) ? $names[$a->getUserId()] : "ÿ";
			$name_b = array_key_exists($b->getUserId(), $names) ? $names[$b->getUserId()] : "ÿ";

			return strcasecmp($name_a, $name_b);
		};

		if($custom_sort !== null){
			$by_custom = function ($a, $b) use ($custom_sort, $by_name){
				$rating = $custom_sort($a, $b);
				return $rating !== 0 ? $rating :  $by_name($a, $b);
			};

			usort($target_array, $by_custom);
		}else{
			usort($target_array, $by_name);
		}
	}
}
