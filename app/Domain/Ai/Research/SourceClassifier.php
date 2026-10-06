<?php

namespace App\Domain\Ai\Research;

use App\Enums\SourceType;

/**
 * Classifies a research source by its domain. The model's own claim about a
 * source's type is only trusted when the domain independently qualifies as
 * official (the institution's official domain(s), government/education
 * authority domains, official application platforms or scholarship bodies);
 * everything else is a secondary source.
 */
final class SourceClassifier
{
    /** Official application platforms (application_platform). */
    private const PLATFORMS = [
        'ucas.com', 'commonapp.org', 'coalitionforcollegeaccess.org', 'scoir.com', 'applytexas.org', 'ouac.on.ca',
        'educationplannerbc.ca', 'uni-assist.de', 'hochschulstart.de', 'studielink.nl', 'universityadmissions.se',
        'antagning.se', 'studyinfo.fi', 'opintopolku.fi', 'samordnaopptak.no', 'optagelse.dk', 'parcoursup.fr',
        'campusfrance.org', 'uac.edu.au', 'vtac.edu.au', 'qtac.edu.au', 'satac.edu.au', 'tisc.edu.au', 'cao.ie',
        'ukpass.ac.uk', 'liaisonedu.com', 'lsac.org', 'aamc.org', 'sophas.org', 'universitaly.it', 'studyinkorea.go.kr',
    ];

    /** Official scholarship bodies (official_scholarship). */
    private const SCHOLARSHIP_BODIES = [
        'chevening.org', 'fulbright.org', 'fulbrightprogram.org', 'iie.org', 'rhodeshouse.ox.ac.uk', 'gatescambridge.org',
        'daad.de', 'cscuk.fcdo.gov.uk', 'marshallscholarship.org', 'schwarzmanscholars.org', 'knight-hennessy.stanford.edu',
        'mastercardfdn.org', 'commonwealthscholarships.org', 'erasmus-plus.ec.europa.eu', 'mext.go.jp', 'studyinjapan.go.jp',
        'csc.edu.cn', 'campuschina.org', 'si.se', 'vliruos.be', 'nuffic.nl', 'australiaawards.gov.au', 'mfat.govt.nz',
    ];

    private const OFFICIAL_SUBTYPES = [
        SourceType::OfficialProgramme, SourceType::OfficialAdmissions, SourceType::OfficialDepartment,
        SourceType::OfficialFaculty, SourceType::OfficialUniversity, SourceType::OfficialScholarship,
    ];

    /**
     * @param  list<string>  $officialDomains  bare domains believed to belong to the institution / scholarship
     * @return array{type:SourceType, official:bool, rank:int, domain:?string}
     */
    public function classify(string $url, array $officialDomains, ?string $claimedType = null): array
    {
        $host = UrlNormalizer::host($url);
        $domain = UrlNormalizer::domain($url);
        $claimed = SourceType::tryFrom((string) $claimedType);

        if ($host === null) {
            return $this->result(SourceType::Secondary, $domain);
        }

        foreach ($officialDomains as $official) {
            if ($official !== '' && UrlNormalizer::hostMatches($host, $official)) {
                $type = in_array($claimed, self::OFFICIAL_SUBTYPES, true) ? $claimed : SourceType::OfficialUniversity;

                return $this->result($type, $domain);
            }
        }

        foreach (self::SCHOLARSHIP_BODIES as $body) {
            if (UrlNormalizer::hostMatches($host, $body)) {
                return $this->result(SourceType::OfficialScholarship, $domain);
            }
        }

        foreach (self::PLATFORMS as $platform) {
            if (UrlNormalizer::hostMatches($host, $platform)) {
                return $this->result(SourceType::ApplicationPlatform, $domain);
            }
        }

        if ($this->isGovernment($host)) {
            return $this->result(SourceType::Government, $domain);
        }

        return $this->result(SourceType::Secondary, $domain);
    }

    public function isGovernment(string $host): bool
    {
        return preg_match('/(^|\.)(gov|mil)(\.[a-z]{2})?$|(^|\.)govt\.[a-z]{2}$|(^|\.)gouv\.[a-z]{2}$|(^|\.)gob\.[a-z]{2}$|(^|\.)go\.[a-z]{2}$|(^|\.)gc\.ca$|(^|\.)canada\.ca$|(^|\.)europa\.eu$|(^|\.)admin\.ch$|(^|\.)bund\.de$/', $host) === 1;
    }

    /** @return array{type:SourceType, official:bool, rank:int, domain:?string} */
    private function result(SourceType $type, ?string $domain): array
    {
        return ['type' => $type, 'official' => $type->isOfficial(), 'rank' => $type->rank(), 'domain' => $domain];
    }
}
