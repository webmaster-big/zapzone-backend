<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\WaiverTemplate;
use App\Models\WaiverTemplateVersion;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

class DefaultWaiverTemplateSeeder extends Seeder
{
    // Unique keys embedded in internal_description for idempotency checks
    public const KEY_GENERAL      = 'system:tpl_general_v1';
    public const KEY_RAGE_ROOM    = 'system:tpl_rage_room_v1';
    public const KEY_AXE_THROWING = 'system:tpl_axe_throwing_v1';
    public const KEY_PAINT_ROOM   = 'system:tpl_paint_room_v1';
    public const KEY_EVENT        = 'system:tpl_event_v1';

    /** Legacy key — kept so the old single-template seeder is detected and not re-run. */
    public const DEFAULT_KEY = 'system:default_general_waiver_v1';

    private static array $definitions = [];

    public function run(): void
    {
        $companies = Company::all();
        foreach ($companies as $company) {
            self::seedForCompany($company);
        }
        $this->command?->info("Default waiver templates seeded for {$companies->count()} company/companies.");
    }

    /**
     * Seed all four default templates for a company. Each key is checked
     * independently — safe to re-run and safe to add new keys in the future.
     */
    public static function seedForCompany(Company $company): void
    {
        foreach (self::getDefinitions() as $def) {
            $exists = WaiverTemplate::where('company_id', $company->id)
                ->where('internal_description', 'like', '%' . $def['key'] . '%')
                ->exists();

            if ($exists) {
                continue;
            }

            try {
                $template = WaiverTemplate::create(array_merge(
                    self::commonFields($company->id),
                    [
                        'title'                       => $def['title'],
                        'internal_description'        => $def['description'] . ' [' . $def['key'] . ']',
                        'is_default'                  => $def['is_default'],
                        'body_text'                   => $def['body'],
                        'minor_section_enabled'       => $def['minor_section_enabled'],
                        'dob_required'                => $def['dob_required'],
                        'relationship_required'       => $def['relationship_required'],
                        'photo_video_release_enabled' => $def['photo_video'],
                        'medical_ack_enabled'         => $def['medical_ack'],
                        'property_damage_enabled'     => $def['property_damage'],
                        'group_leader_clause_enabled' => $def['group_leader'],
                    ]
                ));

                WaiverTemplateVersion::create([
                    'waiver_template_id' => $template->id,
                    'version'            => 1,
                    'body_text'          => $def['body'],
                    'clause_config'      => [
                        'minor_section_enabled'       => $def['minor_section_enabled'],
                        'dob_required'                => $def['dob_required'],
                        'relationship_required'       => $def['relationship_required'],
                        'photo_video_release_enabled' => $def['photo_video'],
                        'medical_ack_enabled'         => $def['medical_ack'],
                        'property_damage_enabled'     => $def['property_damage'],
                        'group_leader_clause_enabled' => $def['group_leader'],
                        'electronic_consent_enabled'  => true,
                    ],
                    'created_by' => null,
                ]);

                Log::info('Default waiver template seeded', [
                    'company_id'  => $company->id,
                    'template_id' => $template->id,
                    'key'         => $def['key'],
                ]);
            } catch (\Throwable $e) {
                Log::warning('Failed to seed waiver template', [
                    'company_id' => $company->id,
                    'key'        => $def['key'],
                    'error'      => $e->getMessage(),
                ]);
            }
        }
    }

    // -------------------------------------------------------------------------
    // Shared fields applied to every template
    // -------------------------------------------------------------------------
    private static function commonFields(int $companyId): array
    {
        return [
            'company_id'              => $companyId,
            'location_id'             => null,
            'status'                  => WaiverTemplate::STATUS_DRAFT,
            'current_version'         => 1,
            'validity_duration_days'  => null,
            'max_minors'              => 10,
            'duplicate_rule'          => 'manager_only',
            'reminder_eligible'       => true,
            'assigned_package_ids'    => [],
            'assigned_attraction_ids' => [],
            'assigned_event_ids'      => [],
            'assigned_party_types'    => [],
            'electronic_consent_enabled' => true,
            'marketing_consent_enabled'  => true,
            'marketing_consent_text'     => 'I agree to allow {{business_legal_name}} to use my personal information for future marketing, promotions, special offers, birthday offers, and customer communications.',
            'marketing_helper_text'      => 'This consent is optional and is not required to participate or complete this waiver. If you do not check this box, your information will only be used for waiver recordkeeping and operational purposes related to your visit.',
            'crm_sync_allowed'        => false,
            'crm_sync_birthday'       => false,
            'crm_sync_minor'          => false,
            'attorney_reviewed'       => false,
        ];
    }

    // -------------------------------------------------------------------------
    // Template definitions
    // -------------------------------------------------------------------------
    private static function getDefinitions(): array
    {
        if (!empty(self::$definitions)) {
            return self::$definitions;
        }

        self::$definitions = [
            [
                'key'                   => self::KEY_GENERAL,
                'title'                 => 'General Activity Waiver & Release of Liability',
                'description'           => 'Catch-all waiver for parties, laser tag, events, and general activities. Assign specific attractions to the Rage Room, Axe Throwing, or Paint Room templates.',
                'is_default'            => true,
                'minor_section_enabled' => true,
                'dob_required'          => true,
                'relationship_required' => true,
                'photo_video'           => true,
                'medical_ack'           => true,
                'property_damage'       => true,
                'group_leader'          => true,
                'body'                  => self::generalBody(),
            ],
            [
                'key'                   => self::KEY_RAGE_ROOM,
                'title'                 => 'Rage Room Waiver & Release of Liability',
                'description'           => 'Specific waiver for Rage Room attraction. Assign this template to Rage Room attractions in the builder.',
                'is_default'            => false,
                'minor_section_enabled' => true,
                'dob_required'          => true,
                'relationship_required' => true,
                'photo_video'           => true,
                'medical_ack'           => true,
                'property_damage'       => true,
                'group_leader'          => false,
                'body'                  => self::rageRoomBody(),
            ],
            [
                'key'                   => self::KEY_AXE_THROWING,
                'title'                 => 'Axe Throwing Waiver & Release of Liability',
                'description'           => 'Specific waiver for Axe Throwing. Typically 18+ — minor section is disabled by default. Assign to Axe Throwing attractions in the builder.',
                'is_default'            => false,
                'minor_section_enabled' => false,
                'dob_required'          => false,
                'relationship_required' => false,
                'photo_video'           => true,
                'medical_ack'           => true,
                'property_damage'       => true,
                'group_leader'          => false,
                'body'                  => self::axeThrowingBody(),
            ],
            [
                'key'                   => self::KEY_PAINT_ROOM,
                'title'                 => 'Paint Room Waiver & Release of Liability',
                'description'           => 'Specific waiver for Paint Room / Splatter Room. Assign this template to Paint Room attractions in the builder.',
                'is_default'            => false,
                'minor_section_enabled' => true,
                'dob_required'          => true,
                'relationship_required' => true,
                'photo_video'           => true,
                'medical_ack'           => true,
                'property_damage'       => true,
                'group_leader'          => false,
                'body'                  => self::paintRoomBody(),
            ],
            [
                'key'                   => self::KEY_EVENT,
                'title'                 => 'Event Participation Waiver & Release of Liability',
                'description'           => 'Waiver for ticketed events, special events, and event purchases. Assign this template to specific events in the builder. {{activity_name}} fills in automatically with the event name.',
                'is_default'            => false,
                'minor_section_enabled' => true,
                'dob_required'          => true,
                'relationship_required' => true,
                'photo_video'           => true,
                'medical_ack'           => true,
                'property_damage'       => false,
                'group_leader'          => true,
                'body'                  => self::eventBody(),
            ],
        ];

        return self::$definitions;
    }

    // =========================================================================
    // WAIVER BODIES
    // Tokens ({{...}}) are replaced at render time by WaiverService::render().
    //   {{activity_name}}       → package, attraction, or event name
    //   {{booking_date}}        → selected visit/booking date
    //   {{visit_date}}          → alias for booking_date
    //   {{full_name}}           → adult signer's full name
    //   {{business_legal_name}} → company name (same as {{company_name}})
    //   {{location_name}}       → location name
    //   {{location_address}}    → location address
    //
    // IMPORTANT: Generic planning language. Michigan-licensed attorney must
    // review all templates before activation.
    // =========================================================================

    // -------------------------------------------------------------------------
    // 1. General / Catch-all (parties, laser tag, events, general attractions)
    // -------------------------------------------------------------------------
    private static function generalBody(): string
    {
        return <<<'WAIVER'
ACCIDENT WAIVER AND RELEASE OF LIABILITY FORM

Must be completed by all adult participants.
Must be completed by a parent or legal guardian for any participant under the age of 18.

Business: {{business_legal_name}}
Location: {{location_name}} — {{location_address}}
Activity / Event: {{activity_name}}
Visit Date: {{booking_date}}
Adult Participant or Parent/Guardian: {{full_name}}

By submitting this Waiver, I acknowledge that I am voluntarily participating in, or allowing the minor participant(s) listed by me to participate in, recreational, entertainment, or party activities provided by {{business_legal_name}}.


1. ASSUMPTION OF RISK

I voluntarily assume all risks of participating in activities at {{business_legal_name}}, including risks arising from: the actions of other guests; physical movement, running, jumping, slipping, or falling; contact with floors, walls, equipment, or other people; use of equipment, props, games, or facility property; dangerous or defective equipment; and negligence or fault of {{business_legal_name}}, its owners, staff, agents, or assigns, to the extent allowed by law.

Injuries may include but are not limited to: cuts, bruises, sprains, broken bones, eye injuries, head injuries, emotional distress, serious injury, permanent disability, or death.


2. ACTIVITY RISK — LASER TAG / GENERAL ACTIVE ATTRACTIONS

I understand that active attractions may involve running, sudden stops, low lighting, obstacles, physical exertion, and contact with equipment or other participants. All participants must follow posted rules and staff instructions at all times.


3. AGE RESTRICTIONS

I confirm that I, or the listed participant(s), meet the required age rules for the selected activity. If completing this Waiver for minors, I confirm that I am the parent, legal guardian, or authorized adult responsible for each listed minor.


4. HEALTH AND MEDICAL ACKNOWLEDGMENT

I understand certain activities may not be appropriate for persons with medical conditions including: asthma; epilepsy; heart or cardiovascular conditions; respiratory conditions; hypertension; skeletal, joint, or mobility conditions; pregnancy; or other conditions that may make participation unsafe. I certify that I, and any listed minor(s), do not have any condition that would prevent safe participation, or I voluntarily assume all associated risks. I have adequate insurance to cover any injury, or I agree to personally bear the cost.


5. DRUGS, ALCOHOL, AND IMPAIRMENT

I certify that I am not under the influence of alcohol, drugs, or any substance that may impair my ability to participate safely. {{business_legal_name}} may refuse participation if staff believe any participant is impaired or unsafe.


6. SAFETY RULES

All participants must: follow posted rules; follow staff instructions; complete required safety briefings; wear required protective gear; use equipment only as directed; and immediately report unsafe conditions or damaged equipment to staff.


7. RELEASE OF LIABILITY

To the fullest extent allowed by Michigan law, I waive, release, and discharge {{business_legal_name}}, its owners, officers, managers, employees, staff, agents, contractors, vendors, landlords, insurers, successors, and assigns from any and all liability, claims, damages, losses, or expenses arising from participation in the selected activity or use of the facility, including claims related to personal injury, death, disability, property damage, emotional distress, and ordinary negligence of {{business_legal_name}} or its representatives, to the extent allowed by law.


8. INDEMNIFICATION

I agree to indemnify, hold harmless, and not sue {{business_legal_name}} and its representatives for claims arising from my participation or the participation of any listed minor.


9. PARENT / GUARDIAN AGREEMENT FOR MINORS

If signing for minors, I confirm: I am the parent, legal guardian, or authorized adult; I have legal authority to sign; I accept responsibility for each listed minor; I consent to their participation; and I have provided each minor's name, date of birth, and my relationship to them.


10. GROUP LEADER / CHAPERONE RESPONSIBILITY

If I am the group leader or chaperone, I understand I may be responsible for communicating waiver requirements to my group and that each participant must complete a waiver before participating. If I am the registered guest with a card on file, I may be responsible for charges, damages, or unpaid balances associated with my group per the applicable booking terms.


11. PROPERTY DAMAGE

I agree that {{business_legal_name}} may charge guests or group leaders for damage caused to company property, equipment, rooms, or facility property.


12. EMERGENCY MEDICAL TREATMENT

I consent to medical treatment advisable in the event of injury during participation. If signing for minors, I authorize emergency medical treatment for listed minor(s) if reasonably necessary. I am responsible for any medical expenses incurred.


13. PHOTO / VIDEO RELEASE (OPTIONAL)

I understand that participants may be photographed or recorded at the facility. If you agree to optional photo/video marketing use, please check the option below. Declining does not affect waiver completion.


14. GOVERNING LAW AND SEVERABILITY

This Waiver is governed by the laws of the State of Michigan. If any provision is found invalid or unenforceable, the remaining provisions remain in full effect.


15. ELECTRONIC AGREEMENT AND CONSENT

By checking the agreement box, I consent to the use of an electronic agreement in place of a paper signature. I may request a paper copy at no charge and may withdraw electronic consent in writing at any time without penalty.


16. FINAL ACKNOWLEDGMENT

I certify that I have read this Waiver, understand it is a release of liability and a contract, and agree to its terms voluntarily.

Typed Full Legal Name: {{full_name}}
Visit Date: {{visit_date}}
Submitted: {{current_date}}

WAIVER;
    }

    // -------------------------------------------------------------------------
    // 2. Rage Room
    // -------------------------------------------------------------------------
    private static function rageRoomBody(): string
    {
        return <<<'WAIVER'
RAGE ROOM — ACCIDENT WAIVER AND RELEASE OF LIABILITY FORM

Must be completed by all participants.
Must be completed by a parent or legal guardian for any participant under the age of 18.

Business: {{business_legal_name}}
Location: {{location_name}} — {{location_address}}
Activity: {{activity_name}}
Visit Date: {{booking_date}}
Adult Participant or Parent/Guardian: {{full_name}}

By submitting this Waiver, I acknowledge that I am voluntarily participating in, or allowing the listed minor(s) to participate in, a Rage Room activity provided by {{business_legal_name}}.


1. ASSUMPTION OF RISK — RAGE ROOM

I understand and accept that engaging in a Rage Room activity is an inherently dangerous activity.

I voluntarily assume all risks, including risks arising from: breaking or striking objects; use of provided tools, bats, or objects; flying or falling debris; sharp or jagged edges on broken items; damaged props or equipment; loud noises or concussive forces; physical exertion or fatigue; slipping on debris or surfaces; actions of other participants; and negligence or fault of {{business_legal_name}}, its owners, staff, agents, or assigns, to the extent allowed by law.

Injuries may include but are not limited to: cuts, lacerations, punctures, bruises, eye injuries, head injuries, broken bones, serious injury, permanent disability, or death.

I understand that protective gear is required and reduces but does not eliminate risk.


2. AGE RESTRICTIONS

I confirm that I, and any listed participant(s), meet the minimum age requirement for the Rage Room as set by {{business_legal_name}}. No participant may enter unless they meet the minimum age and safety requirements. If completing this Waiver for a minor, I confirm I am the parent, legal guardian, or authorized adult responsible for the listed minor.


3. PROTECTIVE GEAR REQUIREMENT

All participants must wear all required protective gear — including but not limited to protective eyewear, gloves, and any other gear provided or required by {{business_legal_name}} — for the entire duration of the activity. I may be removed from the activity if I refuse to wear required protective gear.


4. HEALTH AND MEDICAL ACKNOWLEDGMENT

I understand the Rage Room may not be appropriate for persons with medical conditions including: asthma; epilepsy; heart or cardiovascular conditions; respiratory conditions; hypertension; skeletal, joint, or mobility conditions; pregnancy; anxiety or stress-related conditions; or other conditions that may be aggravated by strenuous physical activity, loud sounds, or sudden movement.

I certify that I, and any listed minor(s), do not have any condition that would prevent safe participation, or I voluntarily assume all associated risks.


5. DRUGS, ALCOHOL, AND IMPAIRMENT

I certify that I am not under the influence of alcohol, drugs, controlled substances, or any substance that may impair my judgment, coordination, or ability to safely participate. {{business_legal_name}} reserves the right to refuse participation if staff believe any participant is impaired or emotionally distressed.


6. SAFETY RULES AND STAFF AUTHORITY

All participants must: follow all posted rules and staff instructions; wear all required protective gear for the entire activity; use provided tools only as directed; not remove protective gear until instructed; remain within designated activity boundaries; and immediately notify staff of unsafe conditions, injuries, or concerns.

Staff may, in their sole discretion, stop participation or remove a participant from the activity or premises if participation becomes unsafe.


7. RELEASE OF LIABILITY

To the fullest extent allowed by Michigan law, I waive, release, and discharge {{business_legal_name}}, its owners, officers, managers, employees, staff, agents, contractors, vendors, landlords, insurers, successors, and assigns from any and all liability, claims, damages, losses, or expenses arising from participation in the Rage Room or use of the facility, including claims related to personal injury, death, disability, property damage, emotional distress, actions of other participants, and ordinary negligence of {{business_legal_name}} or its representatives, to the extent allowed by law.


8. INDEMNIFICATION

I agree to indemnify, hold harmless, and not sue {{business_legal_name}} and its representatives for claims arising from my participation or the participation of any listed minor.


9. PARENT / GUARDIAN AGREEMENT FOR MINORS

If signing for minors, I confirm: I am the parent, legal guardian, or authorized adult; I have legal authority to sign; I accept full responsibility for each listed minor; I consent to their participation in the Rage Room; and I have provided each minor's name, date of birth, and my relationship to them.


10. PROPERTY DAMAGE

I agree that {{business_legal_name}} may charge me or my group for damage beyond the scope of normal Rage Room activity, including damage to protected areas, equipment, structural elements, or facility property not intended to be broken.


11. EMERGENCY MEDICAL TREATMENT

I consent to medical treatment advisable in the event of injury during participation. If signing for minors, I authorize emergency medical treatment for listed minor(s) if reasonably necessary. I am responsible for any medical expenses incurred.


12. PHOTO / VIDEO RELEASE (OPTIONAL)

I understand that participants may be photographed or recorded at the facility. If you agree to optional photo/video marketing use, please check the option below. Declining does not affect waiver completion.


13. GOVERNING LAW AND SEVERABILITY

This Waiver is governed by the laws of the State of Michigan. If any provision is found invalid or unenforceable, the remaining provisions remain in full effect.


14. ELECTRONIC AGREEMENT AND CONSENT

By checking the agreement box, I consent to the use of an electronic agreement in place of a paper signature. I may request a paper copy at no charge and may withdraw electronic consent in writing at any time without penalty.


15. FINAL ACKNOWLEDGMENT

I certify that I have read this Rage Room Waiver, understand it is a release of liability and a contract, and agree to its terms voluntarily and after careful consideration.

Typed Full Legal Name: {{full_name}}
Visit Date: {{visit_date}}
Submitted: {{current_date}}

WAIVER;
    }

    // -------------------------------------------------------------------------
    // 3. Axe Throwing (typically 18+ — minor section disabled by default)
    // -------------------------------------------------------------------------
    private static function axeThrowingBody(): string
    {
        return <<<'WAIVER'
AXE THROWING — ACCIDENT WAIVER AND RELEASE OF LIABILITY FORM

Must be completed by all participants. Participants must meet the minimum age requirement.

Business: {{business_legal_name}}
Location: {{location_name}} — {{location_address}}
Activity: {{activity_name}}
Visit Date: {{booking_date}}
Participant: {{full_name}}

By submitting this Waiver, I acknowledge that I am voluntarily participating in an Axe Throwing activity provided by {{business_legal_name}}.


1. ASSUMPTION OF RISK — AXE THROWING

I understand and accept that axe throwing is an inherently dangerous activity.

I voluntarily assume all risks, including risks arising from: handling, throwing, or retrieving sharp-edged axes; axes missing or ricocheting off targets; axes or fragments striking participants, bystanders, walls, or equipment; physical exertion or fatigue; slipping, tripping, or falling in the throwing area; and negligence or fault of {{business_legal_name}}, its owners, staff, agents, or assigns, to the extent allowed by law.

Injuries may include but are not limited to: cuts, lacerations, punctures, bruises, eye injuries, head injuries, broken bones, serious injury, permanent disability, or death.


2. AGE REQUIREMENTS

I confirm that I meet the minimum age requirement set by {{business_legal_name}} for Axe Throwing participation. Participants who do not meet the minimum age requirement will not be permitted to participate.


3. HEALTH AND MEDICAL ACKNOWLEDGMENT

I understand axe throwing may not be appropriate for persons with conditions including: heart or cardiovascular conditions; epilepsy; skeletal, joint, or mobility limitations; conditions affecting balance, coordination, or grip strength; pregnancy; or other conditions that may affect safe participation.

I certify that I do not have any condition that would prevent safe participation, or I voluntarily assume all associated risks.


4. DRUGS, ALCOHOL, AND IMPAIRMENT

I certify that I am not under the influence of alcohol, drugs, controlled substances, or any substance that may impair my judgment, coordination, balance, or ability to safely handle an axe. {{business_legal_name}} reserves the right to refuse or stop participation if staff believe any participant is impaired or unsafe.


5. SAFETY RULES AND STAFF AUTHORITY

All participants must: attend and complete the required safety briefing before throwing; follow all posted rules and lane boundaries; follow all staff instructions at all times; handle axes only as directed by staff; throw only when instructed by staff; remain behind the throwing line when others are throwing; never retrieve an axe until instructed by staff; and immediately notify staff of any unsafe conditions.

Staff may, in their sole discretion, stop participation or remove a participant from the activity or premises if participation becomes unsafe.


6. RELEASE OF LIABILITY

To the fullest extent allowed by Michigan law, I waive, release, and discharge {{business_legal_name}}, its owners, officers, managers, employees, staff, agents, contractors, vendors, landlords, insurers, successors, and assigns from any and all liability, claims, damages, losses, or expenses arising from participation in Axe Throwing or use of the facility, including claims related to personal injury, death, disability, property damage, emotional distress, actions of other participants, and ordinary negligence of {{business_legal_name}} or its representatives, to the extent allowed by law.


7. INDEMNIFICATION

I agree to indemnify, hold harmless, and not sue {{business_legal_name}} and its representatives for claims arising from my participation, including claims caused by my actions, failure to follow rules, or damage I cause.


8. PROPERTY DAMAGE

I agree that {{business_legal_name}} may charge me for damage I cause to axes, target walls, lane equipment, or other facility property beyond normal wear.


9. EMERGENCY MEDICAL TREATMENT

I consent to medical treatment advisable in the event of injury during participation. I am responsible for any medical expenses incurred.


10. PHOTO / VIDEO RELEASE (OPTIONAL)

I understand that participants may be photographed or recorded at the facility. If you agree to optional photo/video marketing use, please check the option below. Declining does not affect waiver completion.


11. GOVERNING LAW AND SEVERABILITY

This Waiver is governed by the laws of the State of Michigan. If any provision is found invalid or unenforceable, the remaining provisions remain in full effect.


12. ELECTRONIC AGREEMENT AND CONSENT

By checking the agreement box, I consent to the use of an electronic agreement in place of a paper signature. I may request a paper copy at no charge and may withdraw electronic consent in writing at any time without penalty.


13. FINAL ACKNOWLEDGMENT

I certify that I have read this Axe Throwing Waiver, understand it is a release of liability and a contract, and agree to its terms voluntarily and after careful consideration.

Typed Full Legal Name: {{full_name}}
Visit Date: {{visit_date}}
Submitted: {{current_date}}

WAIVER;
    }

    // -------------------------------------------------------------------------
    // 4. Paint Room / Splatter Room
    // -------------------------------------------------------------------------
    private static function paintRoomBody(): string
    {
        return <<<'WAIVER'
PAINT ROOM — ACCIDENT WAIVER AND RELEASE OF LIABILITY FORM

Must be completed by all participants.
Must be completed by a parent or legal guardian for any participant under the age of 18.

Business: {{business_legal_name}}
Location: {{location_name}} — {{location_address}}
Activity: {{activity_name}}
Visit Date: {{booking_date}}
Adult Participant or Parent/Guardian: {{full_name}}

By submitting this Waiver, I acknowledge that I am voluntarily participating in, or allowing the listed minor(s) to participate in, a Paint Room / Splatter Room activity provided by {{business_legal_name}}.


1. ASSUMPTION OF RISK — PAINT ROOM

I voluntarily assume all risks of participating in the Paint Room activity, including risks arising from: contact with paint, dye, or paint-related materials; use of provided tools, rollers, brushes, or other equipment; slipping on painted or wet surfaces; paint contact with skin, hair, or eyes; flying paint or splatter from self or other participants; and negligence or fault of {{business_legal_name}}, its owners, staff, agents, or assigns, to the extent allowed by law.

Effects may include but are not limited to: skin or eye irritation, cuts, bruises, slipping injuries, staining of skin or hair, or allergic reactions to paint materials.


2. PROTECTIVE GEAR AND CLOTHING

I agree to wear all provided or required protective gear for the duration of the activity. I understand that paint or paint-related materials may permanently stain clothing, skin, or hair, and I accept that risk.


3. AGE RESTRICTIONS

I confirm that I, and any listed participant(s), meet the minimum age requirement for Paint Room participation as set by {{business_legal_name}}. If completing this Waiver for minors, I confirm I am the parent, legal guardian, or authorized adult responsible for each listed minor.


4. HEALTH, SKIN SENSITIVITY, AND ALLERGY ACKNOWLEDGMENT

I understand that paint or paint materials may cause skin irritation, eye irritation, or allergic reactions in some individuals. I confirm that I, and any listed minor(s), do not have known allergies or sensitivities to the paint materials used that would prevent safe participation, or I voluntarily assume all associated risks. I certify that I, and any listed minor(s), do not have any other medical condition that would prevent safe participation.


5. DRUGS, ALCOHOL, AND IMPAIRMENT

I certify that I am not under the influence of alcohol, drugs, or any substance that may impair my ability to participate safely. {{business_legal_name}} may refuse participation if staff believe any participant is impaired or unable to safely follow instructions.


6. SAFETY RULES AND STAFF AUTHORITY

All participants must: follow all posted rules and staff instructions; wear required protective gear; stay within designated paint areas; use paint and equipment only as directed; and immediately report unsafe conditions, equipment issues, or concerns to staff.


7. RELEASE OF LIABILITY

To the fullest extent allowed by Michigan law, I waive, release, and discharge {{business_legal_name}}, its owners, officers, managers, employees, staff, agents, contractors, vendors, landlords, insurers, successors, and assigns from any and all liability, claims, damages, losses, or expenses arising from participation in the Paint Room or use of the facility, including claims related to personal injury, skin or eye irritation, allergic reaction, staining, property damage, emotional distress, actions of other participants, and ordinary negligence of {{business_legal_name}} or its representatives, to the extent allowed by law.


8. INDEMNIFICATION

I agree to indemnify, hold harmless, and not sue {{business_legal_name}} and its representatives for claims arising from my participation or the participation of any listed minor.


9. PARENT / GUARDIAN AGREEMENT FOR MINORS

If signing for minors, I confirm: I am the parent, legal guardian, or authorized adult; I have legal authority to sign; I accept full responsibility for each listed minor; I consent to their participation in the Paint Room; and I have provided each minor's name, date of birth, and my relationship to them.


10. PROPERTY DAMAGE

I agree that {{business_legal_name}} may charge me or my group for damage beyond the scope of normal Paint Room activity, including damage to equipment, surfaces, or facility property not intended to be painted.


11. EMERGENCY MEDICAL TREATMENT

I consent to medical treatment advisable in the event of injury during participation. If signing for minors, I authorize emergency medical treatment for listed minor(s) if reasonably necessary. I am responsible for any medical expenses incurred.


12. PHOTO / VIDEO RELEASE (OPTIONAL)

I understand that participants may be photographed or recorded at the facility. If you agree to optional photo/video marketing use, please check the option below. Declining does not affect waiver completion.


13. GOVERNING LAW AND SEVERABILITY

This Waiver is governed by the laws of the State of Michigan. If any provision is found invalid or unenforceable, the remaining provisions remain in full effect.


14. ELECTRONIC AGREEMENT AND CONSENT

By checking the agreement box, I consent to the use of an electronic agreement in place of a paper signature. I may request a paper copy at no charge and may withdraw electronic consent in writing at any time without penalty.


15. FINAL ACKNOWLEDGMENT

I certify that I have read this Paint Room Waiver, understand it is a release of liability and a contract, and agree to its terms voluntarily and after careful consideration.

Typed Full Legal Name: {{full_name}}
Visit Date: {{visit_date}}
Submitted: {{current_date}}

WAIVER;
    }

    // -------------------------------------------------------------------------
    // 5. Event / Special Event
    //    {{activity_name}} fills in the specific event name automatically.
    //    Admin assigns specific event IDs to this template in the builder.
    // -------------------------------------------------------------------------
    private static function eventBody(): string
    {
        return <<<'WAIVER'
EVENT PARTICIPATION WAIVER AND RELEASE OF LIABILITY FORM

Must be completed by all participants.
Must be completed by a parent or legal guardian for any participant under the age of 18.

Business: {{business_legal_name}}
Location: {{location_name}} — {{location_address}}
Event: {{activity_name}}
Event Date: {{booking_date}}
Adult Participant or Parent/Guardian: {{full_name}}

By submitting this Waiver, I acknowledge that I am voluntarily participating in, or allowing the listed minor(s) to participate in, the event identified above ("Event"), offered or hosted by {{business_legal_name}}.


1. ASSUMPTION OF RISK

I voluntarily assume all risks of attending or participating in the Event, including risks arising from: physical activities, games, or interactive elements that may be part of the Event; actions of other attendees or participants; moving about the venue, grounds, or facility; contact with equipment, props, staging, decorations, or other property; crowd conditions; and negligence or fault of {{business_legal_name}}, its owners, staff, agents, or assigns, to the extent allowed by law.

Injuries may include but are not limited to: cuts, bruises, sprains, eye injuries, head injuries, serious injury, permanent disability, or death.


2. AGE RESTRICTIONS

I confirm that I, and any listed participant(s), meet any age or eligibility requirements for this Event as set by {{business_legal_name}}. If completing this Waiver for minors, I confirm I am the parent, legal guardian, or authorized adult responsible for each listed minor.


3. HEALTH AND MEDICAL ACKNOWLEDGMENT

I understand that Event activities may not be appropriate for persons with medical conditions including: heart or cardiovascular conditions; epilepsy; respiratory conditions; skeletal, joint, or mobility conditions; pregnancy; or other conditions that may make participation unsafe. I certify that I, and any listed minor(s), do not have any condition that would prevent safe participation, or I voluntarily assume all associated risks.


4. DRUGS, ALCOHOL, AND IMPAIRMENT

I certify that I am not under the influence of alcohol, drugs, or any substance that may impair my ability to participate safely or supervise any minor. {{business_legal_name}} may refuse participation or remove any participant if staff believe the participant is impaired or unable to safely participate.


5. SAFETY RULES AND STAFF AUTHORITY

All participants must: follow all Event rules, staff instructions, and venue guidelines; remain in designated areas; follow any required safety procedures; and immediately report unsafe conditions or concerns to staff. Staff may remove a participant from the Event or premises if participation is unsafe.


6. RELEASE OF LIABILITY

To the fullest extent allowed by Michigan law, I waive, release, and discharge {{business_legal_name}}, its owners, officers, managers, employees, staff, agents, contractors, vendors, landlords, insurers, successors, and assigns from any and all liability, claims, damages, losses, or expenses arising from attendance at or participation in the Event or use of the facility, including claims related to personal injury, death, disability, emotional distress, actions of other attendees, and ordinary negligence of {{business_legal_name}} or its representatives, to the extent allowed by law.


7. INDEMNIFICATION

I agree to indemnify, hold harmless, and not sue {{business_legal_name}} and its representatives for claims arising from my attendance at the Event or the attendance of any listed minor.


8. PARENT / GUARDIAN AGREEMENT FOR MINORS

If signing for minors, I confirm: I am the parent, legal guardian, or authorized adult; I have legal authority to sign; I accept full responsibility for each listed minor; I consent to their participation in the Event; and I have provided each minor's name, date of birth, and my relationship to them.


9. GROUP LEADER / CHAPERONE RESPONSIBILITY

If I am the group leader or chaperone, I understand that each participant in my group must complete a required waiver before participating. If I am the registered guest with a card on file, I may be responsible for charges, damages, or unpaid balances associated with my group per the applicable booking terms.


10. EMERGENCY MEDICAL TREATMENT

I consent to medical treatment advisable in the event of injury during the Event. If signing for minors, I authorize emergency medical treatment for listed minor(s) if reasonably necessary. I am responsible for any medical expenses incurred.


11. PHOTO / VIDEO RELEASE (OPTIONAL)

I understand that participants may be photographed or recorded at the Event. If you agree to optional photo/video marketing use, please check the option below. Declining does not affect waiver completion.


12. GOVERNING LAW AND SEVERABILITY

This Waiver is governed by the laws of the State of Michigan. If any provision is found invalid or unenforceable, the remaining provisions remain in full effect.


13. ELECTRONIC AGREEMENT AND CONSENT

By checking the agreement box, I consent to the use of an electronic agreement in place of a paper signature. I may request a paper copy at no charge and may withdraw electronic consent in writing at any time without penalty.


14. FINAL ACKNOWLEDGMENT

I certify that I have read this Event Participation Waiver, understand it is a release of liability and a contract, and agree to its terms voluntarily and after careful consideration.

Typed Full Legal Name: {{full_name}}
Event Date: {{visit_date}}
Submitted: {{current_date}}

WAIVER;
    }
}
