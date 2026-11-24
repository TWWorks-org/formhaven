<?php

// Form templates, one page each at /templates/{slug}, rendered by
// resources/views/content/template.blade.php. Every page must carry its own
// fields, intro, variations and questions: a shared layout is fine, copied
// content is not.
return [

    'withdrawal-form' => [
        'name' => 'EU withdrawal form',
        'title' => 'EU withdrawal form template in HTML',
        'description' => 'The model withdrawal form from Annex I(B) of Directive 2011/83/EU as a ready-to-paste HTML form. Requests are stored in the EU and emailed to you.',
        'h1' => 'EU withdrawal form for your web shop, in HTML',
        'intro' => 'The model form from Annex I(B) of the Consumer Rights Directive (2011/83/EU), ready to paste. Customers fill it in on your site, and each request is stored in the EU and emailed to you with the date it arrived.',
        'card' => 'The model form from the Consumer Rights Directive',
        'notice' => 'To [YOUR SHOP NAME, ADDRESS AND EMAIL]: I hereby give notice that I withdraw from my contract for the following goods or service.',
        'submit' => 'Send withdrawal',
        'fields' => [
            ['goods_or_service', 'Goods or service', 'text', true],
            ['ordered_on', 'Ordered on', 'date', false],
            ['received_on', 'Received on', 'date', false],
            ['name', 'Your name', 'text', true],
            ['address', 'Your address', 'textarea', true],
            ['email', 'Your email', 'email', true],
        ],
        'variations' => [
            ['Model form only', 'The fields the directive names, nothing more. Best for a small shop with one kind of product.'],
            ['With an order number', 'Add an input named order_number so you find the order in your shop system in one search.'],
            ['With a reason field', 'An optional textarea named reason. Keep it optional: a customer never has to give a reason to withdraw.'],
        ],
        'faq' => [
            ['Do I have to offer this form?', 'You must give consumers the model withdrawal form with your pre-contract information (Article 6(1)(h) of the directive). Offering it on your website is optional (Article 11(3)). If you do, you must confirm receipt on a durable medium, such as an email, without delay.'],
            ['Does FormHaven send that confirmation to the customer?', 'Not yet. You get each request by email with the date it arrived, and you press Reply to confirm it to the customer.'],
            ['Can a customer withdraw without this form?', 'Yes. The directive also accepts any other clear statement, such as a plain email. The form makes the request easy to give and easy for you to find.'],
            ['Is this legal advice?', 'No. It is the model text from the directive as an HTML form. Check your national rules with your own adviser.'],
        ],
    ],

    'contact-form' => [
        'name' => 'Contact form',
        'title' => 'HTML contact form template that works without a server',
        'description' => 'A copy-paste HTML contact form that sends every message to your inbox. Stored in the EU, spam filtered, no server or plugin needed.',
        'h1' => 'HTML contact form that works without a server',
        'intro' => 'Name, email and message: the form most client sites need. Paste it in, put your FormHaven address in the action, and every message lands in your inbox with Reply going straight to the visitor.',
        'card' => 'Name, email and message',
        'notice' => null,
        'submit' => 'Send message',
        'fields' => [
            ['name', 'Your name', 'text', true],
            ['email', 'Your email', 'email', true],
            ['message', 'Message', 'textarea', true],
        ],
        'variations' => [
            ['Minimal', 'Only email and message. The fewer fields, the more people finish the form.'],
            ['With a phone number', 'Add an input named phone with type tel for clients who would rather be called back.'],
            ['With a subject choice', 'A select named subject (quote, support, other) so you can sort messages in your inbox by it.'],
        ],
        'faq' => [
            ['Where does a message go after the visitor presses Send?', 'It is stored on our EU servers and emailed to you. The visitor sees our thank-you page, with a button back to your site once you save its address, or your own page if you set one.'],
            ['How do I answer the visitor?', 'Press Reply in your email. The visitor\'s address from the email field is set as the reply address.'],
            ['What stops spam?', 'The hidden _gotcha field and a rate limit. Bots that fill in the hidden field go to a separate spam list and are never emailed or counted against your limit. You can also list your sites in the form\'s settings, so a copy of the form on any other site goes to spam too. Messages stuffed with links go to spam on their own, and a form that still gets spam can turn on the invisible bot check or add its own spam words.'],
        ],
    ],

];
