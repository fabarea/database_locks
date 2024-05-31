<?php

defined('TYPO3_MODE') === true || die;

(static function () {

    $lockFactory = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(\TYPO3\CMS\Core\Locking\LockFactory::class);
    $lockFactory->addLockingStrategy(\Vd\VdSite\DatabaseLockingStrategy::class);

})();
