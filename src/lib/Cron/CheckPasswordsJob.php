<?php
/**
 * Created by PhpStorm.
 * User: marius
 * Date: 30.09.17
 * Time: 21:24
 */

namespace OCA\Passwords\Cron;

use OC\BackgroundJob\TimedJob;
use OCA\Passwords\Db\Revision;
use OCA\Passwords\Db\RevisionMapper;
use OCA\Passwords\Helper\SecurityCheck\AbstractSecurityCheckHelper;
use OCA\Passwords\Services\HelperService;

/**
 * Class CheckPasswordsJob
 *
 * @package OCA\Passwords\Cron
 */
class CheckPasswordsJob extends TimedJob {

    /**
     * @var HelperService
     */
    protected $helperService;

    /**
     * @var RevisionMapper
     */
    protected $revisionMapper;

    /**
     * CheckPasswordsJob constructor.
     *
     * @param HelperService  $helperService
     * @param RevisionMapper $revisionMapper
     */
    public function __construct(
        HelperService $helperService,
        RevisionMapper $revisionMapper
    ) {
        // Run once per day
        $this->setInterval(24 * 60 * 60);
        //$this->setInterval(15 * 60);
        $this->helperService  = $helperService;
        $this->revisionMapper = $revisionMapper;
    }

    /**
     * @param $argument
     */
    protected function run($argument): void {
        $securityHelper = $this->helperService->getSecurityHelper();

        if($securityHelper->dbUpdateRequired()) {
            $securityHelper->updateDb();
        }
        $this->checkRevisionStatus($securityHelper);
    }

    /**
     * @param $securityHelper
     */
    protected function checkRevisionStatus(AbstractSecurityCheckHelper $securityHelper): void {
        /** @var Revision[] $revisions */
        $revisions = $this->revisionMapper->findAllMatching(['status', 2, '!=']);
        foreach ($revisions as $revision) {
            $oldStatus = $revision->getStatus();
            $newStatus = $securityHelper->getRevisionSecurityLevel($revision);

            if($oldStatus != $newStatus) {
                $revision->setStatus($newStatus);
                $this->revisionMapper->update($revision);
            }
        }
    }
}