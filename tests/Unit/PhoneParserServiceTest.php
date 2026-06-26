<?php

use App\Services\Bandeja\PhoneParserService;

it('extracts international phone with spaces', function () {
    $parser = new PhoneParserService;
    $result = $parser->parse('Contact me at +34 612 345 678 for details');

    expect($result)->toBe('+34612345678');
});

it('extracts international phone without spaces', function () {
    $parser = new PhoneParserService;
    $result = $parser->parse('Call +5491134567890 anytime');

    expect($result)->toBe('+5491134567890');
});

it('extracts international phone with dashes', function () {
    $parser = new PhoneParserService;
    $result = $parser->parse('Phone: +34-912-345-678');

    expect($result)->toBe('+34912345678');
});

it('extracts spanish mobile phone format', function () {
    $parser = new PhoneParserService;
    $result = $parser->parse('Mi numero es 612 345 678');

    expect($result)->toBe('+34612345678');
});

it('extracts spanish landline format', function () {
    $parser = new PhoneParserService;
    $result = $parser->parse('Llamar al 912 345 678');

    expect($result)->toBe('+34912345678');
});

it('extracts spanish phone without separators', function () {
    $parser = new PhoneParserService;
    $result = $parser->parse('Contact: 612345678');

    expect($result)->toBe('+34612345678');
});

it('returns null when no phone in text', function () {
    $parser = new PhoneParserService;
    $result = $parser->parse('Hello, this is a test message with no phone number');

    expect($result)->toBeNull();
});

it('returns null for text with only digits but not a phone', function () {
    $parser = new PhoneParserService;
    $result = $parser->parse('Reference number 12345');

    expect($result)->toBeNull();
});

it('extracts first phone when multiple are present', function () {
    $parser = new PhoneParserService;
    $result = $parser->parse('Call +34 612 345 678 or +34 698 765 432');

    expect($result)->toBe('+34612345678');
});

it('handles phone in email signature block', function () {
    $parser = new PhoneParserService;
    $text = "Best regards,\nJuan Perez\nTel: +34 912 345 678\nEmail: juan@example.com";
    $result = $parser->parse($text);

    expect($result)->toBe('+34912345678');
});

it('does not match numbers that are too short', function () {
    $parser = new PhoneParserService;
    $result = $parser->parse('Call 12345');

    expect($result)->toBeNull();
});
