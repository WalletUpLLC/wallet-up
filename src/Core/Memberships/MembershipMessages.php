<?php
namespace WalletUpPro\Core\Memberships;

if (!defined('ABSPATH')) {
    exit;
}

class MembershipMessages
{
    public const TOKENS = ['{member_name}', '{tier_name}', '{amount}', '{renews_on}', '{site_name}'];

    /** @return array<int,array{key:string,name:string,subject:string,body:string}> */
    public static function all(): array
    {
        return [
            [
                'key'     => 'blank',
                'name'    => __('Write my own', 'walletup'),
                'subject' => '',
                'body'    => '',
            ],
            [
                'key'     => 'renewal',
                'name'    => __('Renewal reminder', 'walletup'),
                'subject' => __('Your {tier_name} membership renews on {renews_on}', 'walletup'),
                'body'    => __("Hi {member_name},\n\nA quick note that your {tier_name} membership renews on {renews_on} for {amount}.\n\nThere is nothing you need to do — it renews automatically. Just reply to this email if you would like to make a change.\n\nThank you for your support.", 'walletup'),
            ],
            [
                'key'     => 'benefits',
                'name'    => __('Benefits recap', 'walletup'),
                'subject' => __('What your {tier_name} membership includes', 'walletup'),
                'body'    => __("Hi {member_name},\n\nHere is a reminder of everything included with your {tier_name} membership at {site_name}.\n\nIf there is anything you have not used yet, reply and we will help you get started.", 'walletup'),
            ],
            [
                'key'     => 'payment_issue',
                'name'    => __('Payment needs attention', 'walletup'),
                'subject' => __('We could not process your {tier_name} membership payment', 'walletup'),
                'body'    => __("Hi {member_name},\n\nWe were not able to process the most recent payment for your {tier_name} membership.\n\nYour access is still active. Reply to this email and we will get it sorted with you.", 'walletup'),
            ],
            [
                'key'     => 'welcome',
                'name'    => __('Welcome', 'walletup'),
                'subject' => __('Welcome to {tier_name}', 'walletup'),
                'body'    => __("Hi {member_name},\n\nThank you for joining {site_name} as a {tier_name} member. Your membership is active and your benefits are listed below.\n\nReply to this email any time — it comes straight to us.", 'walletup'),
            ],
            [
                'key'     => 'thank_you',
                'name'    => __('Thank you', 'walletup'),
                'subject' => __('Thank you for supporting {site_name}', 'walletup'),
                'body'    => __("Hi {member_name},\n\nWe wanted to say thank you. Your {tier_name} membership makes a real difference to what we are able to do.\n\nWith gratitude,\n{site_name}", 'walletup'),
            ],
        ];
    }

    public static function find(string $key): ?array
    {
        foreach (self::all() as $tpl) {
            if ($tpl['key'] === $key) {
                return $tpl;
            }
        }
        return null;
    }
}
