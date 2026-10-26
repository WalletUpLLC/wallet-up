<?php
namespace WalletUpPro\Core\Memberships;

if (!defined('ABSPATH')) {
    exit;
}

class MembershipPresets
{
    /** @return array<int,array{key:string,name:string,description:string,tiers:array}> */
    public static function all(): array
    {
        return [
            [
                'key'         => 'community',
                'name'        => __('Nonprofit / Community', 'walletup'),
                'description' => __('Recurring supporter levels for a cause or community.', 'walletup'),
                'tiers'       => [
                    ['name' => __('Friend', 'walletup'),    'amount' => 5,  'billing_interval' => 'monthly', 'badge_color' => '#7c3aed', 'benefits' => [__('Member newsletter', 'walletup'), __('Community updates', 'walletup')]],
                    ['name' => __('Supporter', 'walletup'), 'amount' => 10, 'billing_interval' => 'monthly', 'badge_color' => '#2563eb', 'benefits' => [__('Everything in Friend', 'walletup'), __('Member card', 'walletup'), __('Event invitations', 'walletup')]],
                    ['name' => __('Champion', 'walletup'),  'amount' => 25, 'billing_interval' => 'monthly', 'badge_color' => '#059669', 'benefits' => [__('Everything in Supporter', 'walletup'), __('Annual impact report', 'walletup'), __('Recognition on our site', 'walletup')]],
                ],
            ],
            [
                'key'         => 'creator',
                'name'        => __('Creator / Membership', 'walletup'),
                'description' => __('Fan tiers for a creator, podcast, or publication.', 'walletup'),
                'tiers'       => [
                    ['name' => __('Fan', 'walletup'),      'amount' => 5,  'billing_interval' => 'monthly', 'badge_color' => '#db2777', 'benefits' => [__('Members-only posts', 'walletup'), __('Community access', 'walletup')]],
                    ['name' => __('Superfan', 'walletup'), 'amount' => 15, 'billing_interval' => 'monthly', 'badge_color' => '#7c3aed', 'benefits' => [__('Everything in Fan', 'walletup'), __('Early access', 'walletup'), __('Monthly Q&A', 'walletup')]],
                    ['name' => __('VIP', 'walletup'),      'amount' => 50, 'billing_interval' => 'monthly', 'badge_color' => '#ea580c', 'benefits' => [__('Everything in Superfan', 'walletup'), __('Behind the scenes', 'walletup'), __('Your name in the credits', 'walletup')]],
                ],
            ],
            [
                'key'         => 'club',
                'name'        => __('Club / Association', 'walletup'),
                'description' => __('Annual membership for a club, gym, or association.', 'walletup'),
                'tiers'       => [
                    ['name' => __('Individual', 'walletup'), 'amount' => 40,  'billing_interval' => 'yearly',   'badge_color' => '#2563eb', 'benefits' => [__('Full member access', 'walletup'), __('Member events', 'walletup')]],
                    ['name' => __('Family', 'walletup'),     'amount' => 75,  'billing_interval' => 'yearly',   'badge_color' => '#059669', 'benefits' => [__('Covers your household', 'walletup'), __('Member events', 'walletup'), __('Guest passes', 'walletup')]],
                    ['name' => __('Lifetime', 'walletup'),   'amount' => 500, 'billing_interval' => 'lifetime', 'badge_color' => '#b45309', 'benefits' => [__('Never renew', 'walletup'), __('All member benefits', 'walletup'), __('Founding member recognition', 'walletup')]],
                ],
            ],
            [
                'key'         => 'professional',
                'name'        => __('Professional body', 'walletup'),
                'description' => __('Annual dues for a professional or trade body.', 'walletup'),
                'tiers'       => [
                    ['name' => __('Member', 'walletup'),     'amount' => 99,  'billing_interval' => 'yearly', 'badge_color' => '#0891b2', 'benefits' => [__('Directory listing', 'walletup'), __('Resource library', 'walletup')]],
                    ['name' => __('Professional', 'walletup'), 'amount' => 199, 'billing_interval' => 'yearly', 'badge_color' => '#7c3aed', 'benefits' => [__('Everything in Member', 'walletup'), __('Certification', 'walletup'), __('Event discounts', 'walletup')]],
                    ['name' => __('Enterprise', 'walletup'),  'amount' => 499, 'billing_interval' => 'yearly', 'badge_color' => '#1e293b', 'benefits' => [__('Everything in Professional', 'walletup'), __('Team seats', 'walletup'), __('Priority support', 'walletup')]],
                ],
            ],
        ];
    }
}
