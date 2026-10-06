<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Research source classification, ordered by authority (lower rank = more
 * authoritative). Requirements and claims from official sources win conflicts.
 */
enum SourceType: string implements HasLabel
{
    case OfficialUniversity = 'official_university';
    case OfficialProgramme = 'official_programme';
    case OfficialAdmissions = 'official_admissions';
    case OfficialDepartment = 'official_department';
    case OfficialFaculty = 'official_faculty';
    case OfficialScholarship = 'official_scholarship';
    case Government = 'government';
    case ApplicationPlatform = 'application_platform';
    case Secondary = 'secondary';

    public function getLabel(): string
    {
        return match ($this) {
            self::OfficialUniversity => 'Official university website',
            self::OfficialProgramme => 'Official programme page',
            self::OfficialAdmissions => 'Official admissions page',
            self::OfficialDepartment => 'Official department page',
            self::OfficialFaculty => 'Official faculty / research page',
            self::OfficialScholarship => 'Official scholarship page',
            self::Government => 'Government / education authority',
            self::ApplicationPlatform => 'Official application platform',
            self::Secondary => 'Secondary source',
        };
    }

    public function rank(): int
    {
        return match ($this) {
            self::OfficialProgramme => 1,
            self::OfficialAdmissions => 2,
            self::OfficialUniversity => 3,
            self::OfficialDepartment => 3,
            self::OfficialFaculty => 4,
            self::OfficialScholarship => 2,
            self::Government => 5,
            self::ApplicationPlatform => 5,
            self::Secondary => 9,
        };
    }

    public function isOfficial(): bool
    {
        return $this !== self::Secondary;
    }
}
