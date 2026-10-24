<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Contact / Support labels (PROMPT 10)
|--------------------------------------------------------------------------
|
| NO SUPPORT ADDRESS LIVES IN THIS FILE. Not an example, not a placeholder.
| Every address rendered by the page comes from configuration, so a
| translation file can never become the source of an address nobody reads.
|
| NO GOVERNMENT IDENTITY. The wording is "Customer Support". This platform has
| no verified relationship with any lottery authority, and implying one on a
| contact page would be a claim made to the people least able to check it.
|
| THE DELIVERY STATES BELOW ARE THE HONEST ONES. "sent" is written only for the
| case where a configured provider accepted the message. The others say the
| enquiry was received and a reply may take longer, because that is true.
|
*/

return [
    'meta_title' => 'Contact Customer Support',
    'meta_description' => 'Send a message to our customer support team and get help with results, accounts and general questions.',

    'title' => 'Contact Us',
    'intro' => 'Send us a message and our support team will get back to you.',
    'get_in_touch' => 'Get in touch',
    'support_team' => 'Support team',
    'support_hours' => 'Support hours',
    'support_unavailable' => 'A support contact address has not been configured yet. You can still send a message using the form and we will keep it on file.',

    'form_heading' => 'Send a message',
    'form_hint' => 'All fields are required.',

    'name' => 'Name',
    'email' => 'Email address',
    'subject' => 'Subject',
    'message' => 'Message',
    'submit' => 'Send message',
    'sending' => 'Sending...',
    'message_limit_hint' => 'Up to :max characters.',
    'honeypot_label' => 'Leave this field empty',
    'privacy_notice' => 'We use your message and email address only to reply to you. We do not store your IP address.',
    'reference_label' => 'Your reference:',

    'useful_links' => 'Useful links',
    'link_home' => 'Home',
    'link_about' => 'About us',
    'link_vision' => 'Vision and mission',
    'link_fees' => 'Fees',
    'link_results' => 'Results',
    'link_prize_verification' => 'Prize verification',
    'link_national' => 'National Lottery',
    'link_weekly' => 'Weekly Lottery',
    'link_mega' => 'Mega Lottery',
    'link_pcso' => 'PCSO Lottery',
    'link_terms' => 'Terms',

    'delivery_note_configured' => 'Messages are forwarded to our support inbox.',
    'delivery_note_unconfigured' => 'Message forwarding is not configured yet, so replies may take longer. Your message is still recorded.',

    'validation_failed' => 'Please correct the following and try again.',
    'validation_no_control_characters' => 'This field contains characters that are not allowed.',

    'status' => [
        'sent' => 'Thank you. Your message has been sent to our support team.',
        'received' => 'Thank you. We have received your message.',
        'received_not_configured' => 'Thank you. We have received your message.',
        'received_pending' => 'Thank you. We have received your message.',
        'received_delivery_failed' => 'Thank you. We have received your message.',
        'rate_limited' => 'You have sent several messages recently. Please wait a little while before sending another.',
        'disabled' => 'The contact form is temporarily unavailable. Please try again later.',
        'failed' => 'We could not accept your message just now. Please try again in a few minutes.',
    ],

    'status_detail' => [
        'sent' => 'A copy has been delivered to our support inbox.',
        'received' => 'It is recorded and our team will review it.',
        'received_not_configured' => 'It is recorded and our team will review it. Email forwarding is not configured yet, so a reply may take longer.',
        'received_pending' => 'It is recorded and delivery to our support inbox is still in progress.',
        'received_delivery_failed' => 'It is recorded and our team will review it. Forwarding it by email did not succeed, so a reply may take longer.',
    ],

    'spam_detected' => 'Your message could not be processed.',
    'rate_limited' => 'Too many messages. Please try again later.',
    'not_configured' => 'Not configured',
    'pending' => 'Pending',
    'sent' => 'Sent',
    'received' => 'Received',
    'delivery_failed' => 'Delivery failed',
];
