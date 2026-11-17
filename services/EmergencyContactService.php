<?php
/** Shared optional emergency-contact fields for application drafts and ID records. */
class EmergencyContactService
{
    public const FIELD_MAP = [
        'emergencyName' => 'emergency_contact_name',
        'emergencyRelation' => 'emergency_contact_relation',
        'emergencyPhone' => 'emergency_contact_phone',
        'emergencyAddress' => 'emergency_contact_address',
    ];

    public static function draftFields(array $application = []): array
    {
        $fields = [];
        foreach (self::FIELD_MAP as $draftKey => $column) {
            $fields[$draftKey] = (string) ($application[$column] ?? '');
        }
        return $fields;
    }

    public static function applicationFields(array $draft): array
    {
        $fields = [];
        foreach (self::FIELD_MAP as $draftKey => $column) {
            $value = is_string($draft[$draftKey] ?? null) ? trim($draft[$draftKey]) : '';
            if ($draftKey === 'emergencyPhone') {
                $value = preg_replace('/[\s()-]/', '', $value);
            }
            $fields[$column] = $value === '' ? null : $value;
        }
        return $fields;
    }
}
