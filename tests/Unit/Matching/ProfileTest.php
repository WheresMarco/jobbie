<?php

declare(strict_types=1);

use App\Matching\Profile;

it('adds roles and skills last in the lists and first in the search terms, without repeats', function (): void {
    $profile = Profile::fromArray([
        'roles' => ['Webbutvecklare'],
        'skills' => ['PHP', 'Laravel'],
        'title_terms' => ['utvecklare'],
        'keywords' => ['php'],
    ])->edited(['roles' => ['Tech lead', 'webbutvecklare'], 'skills' => ['Kubernetes', 'PHP']], ['roles' => [], 'skills' => []]);

    expect($profile->roles)->toBe(['Webbutvecklare', 'Tech lead'])
        ->and($profile->skills)->toBe(['PHP', 'Laravel', 'Kubernetes'])
        ->and($profile->titleTerms)->toBe(['Tech lead', 'webbutvecklare', 'utvecklare'])
        ->and($profile->keywords)->toBe(['Kubernetes', 'PHP'])
        ->and(Profile::contains($profile->skills, ' kubernetes '))->toBeTrue()
        ->and(Profile::contains($profile->skills, 'Docker'))->toBeFalse();
});

it('drops removed roles and skills, and the search terms that contain them', function (): void {
    $profile = Profile::fromArray([
        'roles' => ['Webbutvecklare', 'WordPress-utvecklare', 'Projektledare'],
        'skills' => ['PHP', 'WordPress', 'React', 'React Native', 'JavaScript'],
        'title_terms' => ['utvecklare', 'wordpressutvecklare', 'WordPress-utvecklare', 'PHP-utvecklare', 'teknisk projektledare'],
        'keywords' => ['php', 'react native', 'javascript', 'wordpress'],
    ])->edited(['roles' => [], 'skills' => ['React']], ['roles' => ['projektledare'], 'skills' => ['WordPress', 'React', 'Java']]);

    expect($profile->roles)->toBe(['Webbutvecklare', 'WordPress-utvecklare'])
        // Added back after being removed: the user's word wins.
        ->and($profile->skills)->toBe(['PHP', 'React Native', 'JavaScript', 'React'])
        // Whole words only: "Java" leaves JavaScript and "wordpressutvecklare" stays.
        ->and($profile->titleTerms)->toBe(['utvecklare', 'wordpressutvecklare', 'PHP-utvecklare'])
        ->and($profile->keywords)->toBe(['React', 'php', 'javascript']);
});
