<?php
/**
 * Who counts as a qualifying child of a Solo Parent (RA 11861, the Expanded Solo Parents Welfare Act): a child living with and
 * dependent on the Solo Parent, unmarried and unemployed, who is
 *   - not more than 22 years old, or
 *   - over 22 but unable to fully take care of themselves because of a physical or mental disability or condition.
 * A Solo Parent needs at least one qualifying child. Proof of the disability for a child over 22: a PWD ID or a medical certificate.
 *
 * Pure: dates in, statuses out. Used by the renewal form, the automated check and the staff interview card. These are guides for
 * MSWDO staff; the interview decides.
 */
final class SoloParentChildRules
{
    /** "not more than twenty-two (22) years of age": 22 still qualifies, 23 and over needs the disability. */
    public const MAX_AGE = 22;

    /** The document asked when a child over 22 is marked as having a disability (new application and renewal). */
    public const PROOF_KEY = 'childDisabilityProof';
    public const PROOF_LABEL = 'PWD ID or medical certificate of the child with a disability';
    /** Shown (and enforced on the server) when no child qualifies. */
    public const NONE_QUALIFIES = 'No child qualifies, so a Solo Parent ID cannot be issued (RA 11861). Add a child who is 22 or younger, or tick "This child has a disability" for a child over 22.';
    public const ANSWERED_YES_NO_TICK = 'You answered Yes to "Do you have a child with a disability?" Tick the child who has a disability, or answer No.';

    public const QUALIFIES = 'qualifies';
    public const QUALIFIES_DISABILITY = 'qualifies_disability';
    public const OVER_AGE = 'over_age';
    public const INVALID = 'invalid';

    /** Whole years between $birthdate and $onDate (Y-m-d), or null for a missing, invalid or future birthdate. */
    public static function age(string $birthdate, string $onDate): ?int
    {
        $birth = DateTimeImmutable::createFromFormat('!Y-m-d', $birthdate);
        $on = DateTimeImmutable::createFromFormat('!Y-m-d', $onDate);
        if (!$birth || $birth->format('Y-m-d') !== $birthdate || !$on || $birth > $on) {
            return null;
        }
        return $birth->diff($on)->y;
    }

    /** A child row (birthdate, and has_disability or disability) -> QUALIFIES, QUALIFIES_DISABILITY, OVER_AGE or INVALID. */
    public static function status(array $child, string $onDate): string
    {
        $age = self::age((string) ($child['birthdate'] ?? ''), $onDate);
        if ($age === null) {
            return self::INVALID;
        }
        if ($age <= self::MAX_AGE) {
            return self::QUALIFIES;
        }
        return self::hasDisability($child) ? self::QUALIFIES_DISABILITY : self::OVER_AGE;
    }

    public static function hasDisability(array $child): bool
    {
        return !empty($child['has_disability']) || !empty($child['disability']);
    }

    /** True when at least one child qualifies. */
    public static function anyQualifies(array $children, string $onDate): bool
    {
        foreach ($children as $child) {
            if (in_array(self::status($child, $onDate), [self::QUALIFIES, self::QUALIFIES_DISABILITY], true)) {
                return true;
            }
        }
        return false;
    }

    /** True when a child over 22 is marked with a disability, so a PWD ID or medical certificate is needed. */
    public static function needsDisabilityProof(array $children, string $onDate): bool
    {
        foreach ($children as $child) {
            if (self::status($child, $onDate) === self::QUALIFIES_DISABILITY) {
                return true;
            }
        }
        return false;
    }

    /** The extra required document for a child over 22 with a disability, or null when none is needed. The reason names the child. */
    public static function proofRequirement(array $children, string $onDate): ?array
    {
        foreach ($children as $child) {
            if (self::status($child, $onDate) === self::QUALIFIES_DISABILITY) {
                $name = trim((string) ($child['name'] ?? $child['full_name'] ?? ''));
                $age = self::age((string) ($child['birthdate'] ?? ''), $onDate);
                return ['id' => 0, 'category_id' => null, 'doc_key' => self::PROOF_KEY, 'label' => self::PROOF_LABEL, 'is_required' => 1, 'sort_order' => 9999,
                    'reason' => 'Needed because ' . ($name !== '' ? $name . ' (age ' . $age . ')' : 'a child') . ' is over 22 and has a disability (RA 11861).'];
            }
        }
        return null;
    }

    /** Short plain words for one child's status, for the screens. */
    public static function label(array $child, string $onDate): string
    {
        $age = self::age((string) ($child['birthdate'] ?? ''), $onDate);
        return match (self::status($child, $onDate)) {
            self::QUALIFIES => 'Age ' . $age . ' — qualifies (22 or younger)',
            self::QUALIFIES_DISABILITY => 'Age ' . $age . ' — qualifies with a disability',
            self::OVER_AGE => 'Age ' . $age . ' — over 22 without a disability: does not qualify',
            default => 'Check the birthdate',
        };
    }
}
