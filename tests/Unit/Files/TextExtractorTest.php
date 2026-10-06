<?php

use App\Domain\Files\TextExtractor;

it('restores real hyphens that pdftotext dropped at line ends, but keeps word breaks joined', function () {
    $readingOrder = "Kwame Nkrumah University of Science and Technology (20192023)\nan experience during the COVID19 response\n";
    $raw = "Kwame Nkrumah University of Science and Technology (2019-\n2023)\nan experi-\nence during the COVID-\n19 response\n";

    expect(TextExtractor::restoreLineEndHyphens($readingOrder, $raw))
        ->toBe("Kwame Nkrumah University of Science and Technology (2019-2023)\nan experience during the COVID-19 response\n");
});

it('leaves text without line-end hyphens untouched', function () {
    expect(TextExtractor::restoreLineEndHyphens('BSc Computer Science (2019 to 2023)', 'BSc Computer Science (2019 to 2023)'))
        ->toBe('BSc Computer Science (2019 to 2023)');
});
