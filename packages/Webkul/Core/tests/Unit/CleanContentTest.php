<?php

it('keeps tables and horizontal rules inserted with the admin editor', function () {
    $html = '<table style="border-collapse: collapse; width: 100%;" border="1">'
        .'<colgroup><col style="width: 50%;"><col style="width: 50%;"></colgroup>'
        .'<thead><tr><th scope="col">Model</th><th>Year</th></tr></thead>'
        .'<tbody><tr><td style="vertical-align: top;">Yamaha YZF-R6</td><td colspan="2">2006-2023</td></tr></tbody>'
        .'</table><hr>';

    expect(clean_content($html))
        ->toContain('<table style="border-collapse:collapse;width:100%;" border="1">')
        ->toContain('<col style="width:50%;">')
        ->toContain('<th scope="col">Model</th>')
        ->toContain('<td style="vertical-align:top;">Yamaha YZF-R6</td>')
        ->toContain('<td colspan="2">2006-2023</td>')
        ->toContain('<hr');
});

it('still strips scripts, event handlers and unsafe styles from tables', function () {
    $cleaned = clean_content(
        '<table onmouseover="alert(1)"><tr><td style="background:url(javascript:alert(1))">x</td></tr></table>'
        .'<script>alert(2)</script><iframe src="https://example.com"></iframe>'
    );

    expect($cleaned)
        ->toContain('<td>x</td>')
        ->not->toContain('onmouseover')
        ->not->toContain('javascript')
        ->not->toContain('<script')
        ->not->toContain('<iframe');
});
