<?php

namespace ILIAS\Plugin\LongEssayTask\Writer;

use Edutiek\LongEssayService\Data\Alert;
use Edutiek\LongEssayService\Data\WritingSettings;
use Edutiek\LongEssayService\Data\WritingStep;
use Edutiek\LongEssayService\Data\WritingTask;
use Edutiek\LongEssayService\Writer\Context;
use Edutiek\LongEssayService\Writer\Service;
use Edutiek\LongEssayService\Data\WrittenEssay;
use ILIAS\Plugin\LongEssayTask\Data\Essay;
use ILIAS\Plugin\LongEssayTask\Data\Resource;
use ILIAS\Plugin\LongEssayTask\Data\Writer;
use ILIAS\Plugin\LongEssayTask\Data\WriterHistory;
use ILIAS\Plugin\LongEssayTask\ServiceContext;

class WriterContext extends ServiceContext implements Context
{
    /**
     * List the availabilities for which resources should be provided in the app
     * @see Resource
     */
    const RESOURCES_AVAILABILITIES = [
        Resource::RESOURCE_AVAILABILITY_BEFORE,
        Resource::RESOURCE_AVAILABILITY_DURING
    ];


    /**
     * @inheritDoc
     * here: support a separate url from the plugin config (for development purposes)
     */
    public function getFrontendUrl(): string
    {
        $config = $this->plugin->getConfig();
        if (!empty($config->getWriterUrl())) {
            return $config->getWriterUrl();
        }
        else {
            return  ILIAS_HTTP_PATH
                . "/Customizing/global/plugins/Services/Repository/RepositoryObject/LongEssayTask"
                . "/vendor/edutiek/long-essay-service"
                . "/" . Service::FRONTEND_RELATIVE_PATH;
        }
    }

    /**
     * @inheritDoc
     * here: URL of the writer_service script
     */
    public function getBackendUrl(): string
    {
        return  ILIAS_HTTP_PATH
            . "/Customizing/global/plugins/Services/Repository/RepositoryObject/LongEssayTask/writer_service.php"
            . "?client_id=" . CLIENT_ID;
    }

    /**
     * @inheritDoc
     * here: just get the link to the repo object, the tab will be shown depending on the user permissions
     * The ILIAS session still has to exist, otherwise the user has to log in again
     */
    public function getReturnUrl(): string
    {
        return \ilLink::_getStaticLink($this->object->getRefId(), 'xlet', true, '_writer');
    }


    /**
     * @inheritDoc
     */
    public function getWritingSettings(): WritingSettings
    {
        $repoSettings = $this->localDI->getTaskRepo()->getEditorSettingsById($this->task->getTaskId());
        return new WritingSettings(
            $repoSettings->getHeadlineScheme(),
            $repoSettings->getFormattingOptions(),
            $repoSettings->getNoticeBoards(),
            $repoSettings->isCopyAllowed(),
            $this->plugin->getConfig()->getPrimaryColor(),
            $this->plugin->getConfig()->getPrimaryTextColor()
        );
    }


    /**
     *  @inheritDoc
     */
    public function getWritingTask(): WritingTask
    {
        $writing_end = $this->data->dbTimeToUnix($this->task->getWritingEnd());

        if (!empty($timeExtension = $this->localDI->getWriterRepo()->getTimeExtensionByWriterId(
            $this->getRepoWriter()->getId(), $this->task->getTaskId()))
        ) {
            $writing_end += $timeExtension->getMinutes() * 60;
        }


        return new WritingTask(
            (string) $this->object->getTitle(),
            (string) $this->task->getInstructions(),
            $this->user->getFullname(),
            $writing_end
        );
    }


    /**
     * @inheritDoc
     */
    public function getAlerts(): array
    {
        $alerts = [];
        foreach ($this->localDI->getTaskRepo()->getAlertsByTaskId($this->task->getTaskId()) as $repoAlert) {
            if (empty($repoAlert->getWriterId()) || $repoAlert->getWriterId() == $this->getRepoWriter()->getId()) {
                if (empty($repoAlert->getShownFrom()) || $this->data->dbTimeToUnix($repoAlert->getShownFrom()) < time()) {
                    $alerts[] = New Alert(
                        (string) $repoAlert->getId(),
                        $repoAlert->getMessage(),
                        $this->data->dbTimeToUnix($repoAlert->getShownFrom())
                    );
                }
            }
        }
        return $alerts;
    }

    /**
     * @inheritDoc
     */
    public function getWrittenEssay(): WrittenEssay
    {
        $repoEssay = $this->getRepoEssay();
        return new WrittenEssay(
            $repoEssay->getWrittenText(),
            $repoEssay->getRawTextHash(),
            $repoEssay->getProcessedText(),
            $this->data->dbTimeToUnix($repoEssay->getEditStarted()),
            $this->data->dbTimeToUnix($repoEssay->getEditEnded()),
            !empty($repoEssay->getWritingAuthorized()),
            $this->data->dbTimeToUnix($repoEssay->getWritingAuthorized()),
            !empty($repoEssay->getWritingAuthorized()) ? \ilObjUser::_lookupFullname($repoEssay->getWritingAuthorizedBy()) : null
        );
    }

    /**
     * @inheritDoc
     */
    public function setWrittenEssay(WrittenEssay $writtenEssay): void
    {
        $essay = $this->getRepoEssay()
            ->setWrittenText($writtenEssay->getWrittenText())
            ->setRawTextHash($writtenEssay->getWrittenHash())
            ->setProcessedText($writtenEssay->getProcessedText())
            ->setEditStarted($this->data->unixTimeToDb($writtenEssay->getEditStarted()))
            ->setEditEnded($this->data->unixTimeToDb($writtenEssay->getEditEnded()));

        if ($writtenEssay->isAuthorized()) {
                if (empty($essay->getWritingAuthorized())) {
                    $essay->setWritingAuthorized($this->data->unixTimeToDb(time()));
                }
                if (empty($essay->getWritingAuthorizedBy())) {
                    $essay->setWritingAuthorizedBy($this->user->getId());
                }
        }
        else {
            $essay->setWritingAuthorized(null);
            $essay->setWritingAuthorizedBy(null);
        }

        $this->localDI->getEssayRepo()->updateEssay($essay);
    }

    /**
     * @inheritDoc
     */
    public function getWritingSteps(?int $maximum): array
    {
        $entries = $this->localDI->getEssayRepo()->getWriterHistoryStepsByEssayId(
            $this->getRepoEssay()->getId(),
            $maximum);

        $steps = [];
        foreach ($entries as $entry) {
            $steps[] = new WritingStep(
                (int) ($this->data->dbTimeToUnix($entry->getTimestamp())),
                (string) $entry->getContent(),
                $entry->isIsDelta(),
                $entry->getHashBefore(),
                $entry->getHashAfter()
            );
        }
        return $steps;
    }

    /**
     * @inheritDoc
     */
    public function addWritingSteps(array $steps)
    {
        foreach ($steps as $step) {
            $entry = new WriterHistory();
            $entry->setEssayId($this->getRepoEssay()->getId())
                ->setContent($step->getContent())
                ->setIsDelta($step->isDelta())
                ->setTimestamp($this->data->unixTimeToDb($step->getTimestamp()))
                ->setHashBefore($step->getHashBefore())
                ->setHashAfter($step->getHashAfter());
            $this->localDI->getEssayRepo()->createWriterHistory($entry);
        }
    }

    /**
     * @inheritDoc
     */
    public function hasWritingStepByHashAfter(string $hash_after): bool
    {
        return $this->localDI->getEssayRepo()->ifWriterHistoryExistByEssayIdAndHashAfter(
            $this->getRepoEssay()->getId(),
            $hash_after);
    }

    /**
     * Get or create the essay object from the repository
     * @return Essay
     */
    protected function getRepoEssay() : Essay
    {
        $repo = $this->localDI->getEssayRepo();
        $writer = $this->getRepoWriter();

        $essay = $repo->getEssayByWriterIdAndTaskId($writer->getId(), $writer->getTaskId());
        if (!isset($essay)) {
            $essay = new Essay();
            $essay->setWriterId($writer->getId())
                ->setTaskId($writer->getTaskId())
                ->setUuid($essay->generateUUID4())
                ->setRawTextHash('');
            $repo->createEssay($essay);
        }
        return $essay;
    }

    /**
     * Get or create the writer object from the repository
     * @return Writer
     */
    protected function getRepoWriter() : Writer
    {
        $repo = $this->localDI->getWriterRepo();
        $writer = $repo->getWriterByUserId($this->user->getId(), $this->task->getTaskId());
        if (!isset($writer)) {
            $writer = new Writer();
            $writer->setUserId($this->user->getId())
                ->setTaskId($this->task->getTaskId())
                ->setPseudonym($this->plugin->txt('participant') . ' ' . $this->user->getId());
            $repo->createWriter($writer);
        }
        return $writer;
    }
}