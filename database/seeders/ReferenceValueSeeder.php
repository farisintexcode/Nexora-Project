<?php

namespace Database\Seeders;

use App\Models\ReferenceValue;
use Illuminate\Database\Seeder;

/** Database.md Lookup plan; unverified business/source vocabularies are deliberately unseeded. */
class ReferenceValueSeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            'activity_type' => ['visit' => 'Site visit'],
            'follow_up_type' => ['reminder' => 'Reminder'],
            'document_type' => ['proof_image' => 'Proof image', 'avatar' => 'Profile picture'],
            'party_role' => ['owner' => 'Owner', 'tenant' => 'Tenant', 'occupier' => 'Occupier', 'billing_customer' => 'Billing customer'],
            'position' => ['01' => 'Staff', '02' => 'Administrative', '03' => 'Supervisor', '04' => 'Manager', '05' => 'Director', '99' => 'System Admin', '06' => 'Call Center'],
            'gender' => ['male' => 'Male', 'female' => 'Female'],
            'assignment_type' => ['vacant' => 'Vacant', 'domestic_dr' => 'Domestic DR', 'domestic_ctos' => 'Domestic CTOS', 'domestic_lod' => 'Domestic LOD', 'domestic_lod_ctos' => 'Domestic LOD CTOS', 'com_dr' => 'COM DR', 'com_lod' => 'COM LOD'],
            'priority' => ['low' => 'Low', 'normal' => 'Normal', 'high' => 'High', 'urgent' => 'Urgent'],
            'contact_type' => ['telephone' => 'Telephone', 'mobile' => 'Mobile', 'fax' => 'Fax', 'email' => 'Email'],
            'address_type' => ['billing' => 'Billing', 'contact' => 'Contact'],
            'image_category' => ['street_sign' => 'Street (Sign Board)', 'premise_1' => 'Premise 1', 'premise_2' => 'Premise 2', 'meter' => 'Meter'],
        ];
        foreach (['Johor', 'Kedah', 'Kelantan', 'Kuala Lumpur', 'Melaka', 'Negeri Sembilan', 'Pahang', 'Perak', 'Perlis', 'Pulau Pinang', 'Selangor', 'Terengganu', 'Sabah', 'Sarawak', 'Putrajaya', 'Labuan'] as $state) {
            $categories['state'][str_replace(' ', '_', mb_strtolower($state))] = $state;
        }
        foreach ($categories as $category => $values) {
            foreach ($values as $code => $label) {
                ReferenceValue::query()->firstOrCreate(['category' => $category, 'code' => (string) $code], ['label' => $label]);
            }
        }
    }
}
