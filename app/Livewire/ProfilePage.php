<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Jobs\ScoreProfile;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/**
 * The user's profile on /profil: what they are looking for. Saving it
 * queues scoring of every active posting (App\Matching). Emptying every
 * field removes the profile and its scores.
 */
final class ProfilePage extends Component
{
    /** Items per list; more say nothing more about a person. */
    public const int MAX_ITEMS = 30;

    public const int MAX_ITEM_LENGTH = 60;

    /** The ways of working a profile can accept (JobFilters::REMOTE_OPTIONS without "unknown"). */
    public const array REMOTE_OPTIONS = ['fully', 'hybrid', 'onsite'];

    public string $titles = '';

    public string $skills = '';

    public string $excluded = '';

    public string $cities = '';

    /** @var list<string> */
    public array $remote = [];

    public bool $saved = false;

    public function mount(): void
    {
        $profile = $this->currentUser()->profile;
        if ($profile === null) {
            return;
        }

        $this->titles = implode("\n", $profile->titles);
        $this->skills = implode("\n", $profile->skills);
        $this->excluded = implode("\n", $profile->excluded);
        $this->cities = implode(', ', $profile->cities);
        $this->remote = $profile->remote;
    }

    public function save(): void
    {
        $user = $this->currentUser();

        $titles = self::terms($this->titles);
        $skills = self::terms($this->skills);
        $excluded = self::terms($this->excluded);
        $cities = self::items($this->cities, lowercase: false);
        $remote = array_values(array_intersect(self::REMOTE_OPTIONS, array_filter($this->remote, is_string(...))));

        if ($titles === [] && $skills === [] && $excluded === [] && $cities === [] && $remote === []) {
            DB::transaction(static function () use ($user): void {
                DB::table('job_posting_scores')->where('user_id', $user->id)->delete();
                $user->profile()->delete();
            });
        } else {
            $profile = Profile::query()->updateOrCreate(['user_id' => $user->id], [
                'titles' => $titles,
                'skills' => $skills,
                'excluded' => $excluded,
                'cities' => $cities,
                'remote' => $remote,
            ]);
            ScoreProfile::dispatch($profile);
        }

        $user->unsetRelation('profile');
        $this->mount();
        $this->saved = true;
    }

    /**
     * @return list<string>
     */
    private static function terms(string $input): array
    {
        return self::items($input, lowercase: true);
    }

    /**
     * One item per line or comma, trimmed, without duplicates.
     *
     * @return list<string>
     */
    private static function items(string $input, bool $lowercase): array
    {
        $items = [];
        foreach (preg_split('/[\n,;]+/u', $input) ?: [] as $item) {
            $item = trim((string) preg_replace('/\s+/u', ' ', $item));
            $item = mb_substr($lowercase ? mb_strtolower($item) : $item, 0, self::MAX_ITEM_LENGTH);
            if ($item !== '' && ! in_array(mb_strtolower($item), array_map(mb_strtolower(...), $items), true)) {
                $items[] = $item;
            }
        }

        return array_slice($items, 0, self::MAX_ITEMS);
    }

    public function render(): View
    {
        $profile = $this->currentUser()->profile;

        // Null until the first scoring has run.
        $matching = null;
        if ($profile !== null && $profile->scored_at !== null) {
            $matching = DB::table('job_posting_scores')
                ->join('job_postings', 'job_postings.id', '=', 'job_posting_scores.job_posting_id')
                ->where('job_posting_scores.user_id', $profile->user_id)
                ->where('job_postings.active', true)
                ->where('job_posting_scores.score', '>=', config()->integer('radar.match_threshold'))
                ->count();
        }

        return view('livewire.profile-page', [
            'profile' => $profile,
            'matching' => $matching,
        ])->title(__('ui.nav.profile'));
    }

    private function currentUser(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
