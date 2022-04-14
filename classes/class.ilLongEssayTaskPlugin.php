<?php
/* Copyright (c) 2021 ILIAS open source, Extended GPL, see docs/LICENSE */

use ILIAS\DI\Container;
use ILIAS\Plugin\LongEssayTask\Data\PluginConfig;

/**
 * Basic plugin file
 * @author Fred Neumann <fred.neumann@ilias.de>
 */
class ilLongEssayTaskPlugin extends ilRepositoryObjectPlugin
{
     const ID = "xlet";

    /** @var Container */
    protected $dic;

    /** @var self */
    protected static $instance;


    /**
     * Constructor.
     */
    public function __construct()
    {
        global $DIC;
        $this->dic = $DIC;

        parent::__construct();
    }

    /**
     * @inheritdoc
     */
    protected function init()
    {
        parent::init();
        require_once __DIR__ . '/../vendor/autoload.php';
    }

    /**
     * Get the Plugin name
     * must correspond to the plugin subdirectory
     * @return string
     */
    public function getPluginName()
	{
		return "LongEssayTask";
	}

    /**
     * @inheritdoc
     */
    public function getParentTypes()
    {
        return array("cat", "crs", "grp", "fold");
    }

    /**
     * @inheritdoc
     */
    public function allowCopy()
    {
        return true;
    }

    /**
     * Uninstall custom data of this plugin
     */
    protected function uninstallCustom()
    {
		$tables = ["xlet_access_token", "xlet_alert", "xlet_corr_setting", "xlet_corrector", "xlet_corrector_ass",
			"xlet_corrector_comment", "xlet_corrector_summary", "xlet_crit_points", "xlet_editor_comment",
			"xlet_editor_history", "xlet_editor_notice", "xlet_editor_settings", "xlet_essay", "xlet_grade_level",
			"xlet_object_settings", "xlet_participant", "xlet_plugin_config", "xlet_rating_crit", "xlet_task_settings",
			"xlet_time_extension", "xlet_writer_notice", "xlet_writer", "xlet_writer_comment", "xlet_writer_history",
            "xlet_resource"];

		foreach($tables as $table){
			if ($this->dic->database()->tableExists($table)){
				$this->dic->database()->dropTable($table);
			}
		}

		//TODO RBAC?
    }

    /**
     * Get the plugin instance
     */
    public static function getInstance(): self
    {
        if (!isset(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Get the plugin configuration with loaded values
     */
    public function getConfig(): PluginConfig
    {
        // caching is already done by ActiveRecord
        $config = new PluginConfig();
        $config->read();
        return $config;
    }


    /**
     * Check if the current user has administrative access
     * @return bool
     */
    public function hasAdminAccess()
    {
        return $this->dic->rbac()->system()->checkAccess("visible", SYSTEM_FOLDER_ID);
    }


    /**
     * Get a plugin text and use the variable, if not translated
     * @param string $a_var
     * @return string
     */
    public function txt(string $a_var) : string
    {
        $txt = parent::txt($a_var);
        if (substr($txt, 0, 5) == '-rep_') {
            return $a_var;
        }
        return $txt;
    }

    /**
     * Convert a string timestamp stored in the database to a unix timestamp
     * Respect the time zone of ILIAS
     * @param ?string $db_timestamp
     * @return ?int
     */
    public function dbTimeToUnix(?string $db_timestamp): ?int
    {
        try {
            $datetime = new \ilDateTime($db_timestamp, IL_CAL_DATETIME);
            return $datetime->get(IL_CAL_UNIX);
        }
        catch (Throwable $throwable) {
            return null;
        }
    }

    /**
     * Convert a unix timestamp to a string timestamp stored in the database
     * Respect the time zone of ILIAS
     * @param ?int $unix_timestamp
     * @return ?string
     */
    public function unixTimeToDb(?int $unix_timestamp): ?string {
        try {
            $datetime = new \ilDateTime($unix_timestamp, IL_CAL_UNIX);
            return $datetime->get(IL_CAL_DATETIME);
        }
        catch (Throwable $throwable) {
            return null;
        }
    }

    /**
     * Format a time period from timestamp strings with fallback for missing values
     */
    public function formatPeriod(?string $start, ?string $end): string
    {
        try {
            if(empty($start) && empty($end)) {
                return $this->txt('not_specified');
            }
            elseif (empty($end)) {
                return
                    $this->txt('period_from') . ' '
                    .\ilDatePresentation::formatDate(new \ilDateTime($start, IL_CAL_DATETIME));
            }
            elseif (empty($start)) {
                return
                    \ilDatePresentation::formatDate(new \ilDateTime($end, IL_CAL_DATETIME))
                    . ' ' . $this->txt('period_until');
            }
            else {
                return \ilDatePresentation::formatPeriod(new \ilDateTime($start, IL_CAL_DATETIME), new \ilDateTime($end, IL_CAL_DATETIME));
            }
        }
        catch (Throwable $e) {
            return $this->txt('not_specified');
        }
    }



    public function reloadControlStructure() {
        // load control structure
        $structure_reader = new ilCtrlStructureReader();
        $structure_reader->readStructure(
            true,
            "./" . $this->getDirectory(),
            $this->getPrefix(),
            $this->getDirectory()
        );

        // add config gui to the ctrl calls
        $this->dic->ctrl()->insertCtrlCalls(
            "ilobjcomponentsettingsgui",
            ilPlugin::getConfigureClassName(["name" => $this->getPluginName()]),
            $this->getPrefix()
        );

        $this->readEventListening();
    }
}