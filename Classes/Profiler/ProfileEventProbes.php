<?php

declare(strict_types=1);

namespace Ochorocho\FrankenPhp\Profiler;

use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\TypoScript\IncludeTree\Event\AfterTemplatesHaveBeenDeterminedEvent;
use TYPO3\CMS\Frontend\Event\AfterPageAndLanguageIsResolvedEvent;
use TYPO3\CMS\Frontend\Event\AfterPageWithRootLineIsResolvedEvent;
use TYPO3\CMS\Frontend\Event\AfterTypoScriptDeterminedEvent;
use TYPO3\CMS\Frontend\Event\BeforePageIsResolvedEvent;
use TYPO3\CMS\Frontend\Event\ShouldUseCachedPageDataIfAvailableEvent;

/**
 * Marks and spans for Core code that is final and cannot be decorated
 * (FrontendTypoScriptFactory, SysTemplateRepository): the events Core
 * dispatches around it are the observable boundaries.
 *
 * - "Create PageInformation" ends with the TimeTracker pull (mark
 *   pageinformation.done, set by ProfilingTimeTracker); the next event,
 *   ShouldUseCachedPageDataIfAvailableEvent, follows createSettingsAndSetupConditions().
 * - AfterTypoScriptDeterminedEvent closes the page-cache read plus
 *   createSetupConfigOrFullSetup().
 */
final readonly class ProfileEventProbes
{
    public const string MARK_PAGE_INFORMATION = 'pageinformation.done';
    public const string MARK_SETTINGS = 'settings.done';
    public const string MARK_SYS_TEMPLATE = 'systemplate.determined';
    public const string MARK_TYPOSCRIPT = 'typoscript.determined';
    public const string MARK_REDIRECT_MATCH = 'redirect.match';
    public const string MARK_PAGEINFO_START = 'pageinfo.start';
    public const string MARK_PAGEINFO_ROOTLINE = 'pageinfo.rootline';
    public const string MARK_PAGEINFO_LANGUAGE = 'pageinfo.language';

    public function __construct(
        private RequestProfile $profile,
    ) {}

    #[AsEventListener('ochorocho-frankenphp/profile-settings')]
    public function settingsDetermined(ShouldUseCachedPageDataIfAvailableEvent $event): void
    {
        $this->profile->spanFromMark(
            RequestProfile::KIND_SERVICE,
            'FrontendTypoScriptFactory::createSettingsAndSetupConditions',
            self::MARK_PAGE_INFORMATION
        );
        $this->profile->mark(self::MARK_SETTINGS);
    }

    #[AsEventListener('ochorocho-frankenphp/profile-typoscript')]
    public function typoScriptDetermined(AfterTypoScriptDeterminedEvent $event): void
    {
        $this->profile->spanFromMark(
            RequestProfile::KIND_SERVICE,
            'page cache read + FrontendTypoScriptFactory::createSetupConfigOrFullSetup',
            self::MARK_SETTINGS
        );
        $this->profile->mark(self::MARK_TYPOSCRIPT);
    }

    /**
     * PageInformationFactory::create() dispatches three events; together with
     * the sys_template event they split "Create PageInformation" into its parts.
     */
    #[AsEventListener('ochorocho-frankenphp/profile-pageinfo-start')]
    public function pageResolutionStarts(BeforePageIsResolvedEvent $event): void
    {
        $this->profile->mark(self::MARK_PAGEINFO_START);
    }

    #[AsEventListener('ochorocho-frankenphp/profile-pageinfo-rootline')]
    public function pageAndRootlineResolved(AfterPageWithRootLineIsResolvedEvent $event): void
    {
        $this->profile->spanFromMark(RequestProfile::KIND_SERVICE, 'PageInformation: page record + rootline', self::MARK_PAGEINFO_START);
        $this->profile->mark(self::MARK_PAGEINFO_ROOTLINE);
    }

    #[AsEventListener('ochorocho-frankenphp/profile-pageinfo-language')]
    public function pageAndLanguageResolved(AfterPageAndLanguageIsResolvedEvent $event): void
    {
        $this->profile->spanFromMark(RequestProfile::KIND_SERVICE, 'PageInformation: language, content_from_pid, backend layout, access', self::MARK_PAGEINFO_ROOTLINE);
        $this->profile->mark(self::MARK_PAGEINFO_LANGUAGE);
    }

    #[AsEventListener('ochorocho-frankenphp/profile-systemplate')]
    public function sysTemplatesDetermined(AfterTemplatesHaveBeenDeterminedEvent $event): void
    {
        $this->profile->spanFromMark(RequestProfile::KIND_SERVICE, 'PageInformation: sys_template rows', self::MARK_PAGEINFO_LANGUAGE);
        $this->profile->mark(self::MARK_SYS_TEMPLATE);
    }

    /**
     * EXT:redirects is optional, so the event is named as a string.
     */
    #[AsEventListener(identifier: 'ochorocho-frankenphp/profile-redirects', event: 'TYPO3\CMS\Redirects\Event\BeforeRedirectMatchDomainEvent')]
    public function redirectMatch(object $event): void
    {
        $this->profile->mark(self::MARK_REDIRECT_MATCH);
    }
}
