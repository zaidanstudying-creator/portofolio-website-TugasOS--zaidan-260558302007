<?php

use App\Exceptions\Services\Lms\LmsAdapterUnavailableException;
use App\Models\TaskFile;
use App\Models\User;
use App\Services\Lms\LmsAdapter;
use App\Services\Lms\LmsAdapterRegistry;
use App\Services\Lms\LmsAssignment;
use App\Services\Lms\LmsSubmissionReceipt;

test('LMS registry fails closed when no campus connector is configured', function () {
    $registry = app(LmsAdapterRegistry::class);

    expect($registry->available())->toBe([]);
    expect(fn () => $registry->forPlatform('moodle'))->toThrow(LmsAdapterUnavailableException::class);
});

test('LMS registry exposes only explicitly registered adapters', function () {
    $adapter = new class implements LmsAdapter
    {
        public function platform(): string
        {
            return 'canvas';
        }

        public function label(): string
        {
            return 'Canvas';
        }

        public function assignments(User $user, ?string $courseId = null): array
        {
            return [];
        }

        public function assignment(User $user, string $assignmentId): LmsAssignment
        {
            throw new LogicException('Not called in this registry test.');
        }

        public function findSubmissionByKey(User $user, string $assignmentId, string $idempotencyKey): ?LmsSubmissionReceipt
        {
            throw new LogicException('Not called in this registry test.');
        }

        public function submit(User $user, string $assignmentId, TaskFile $file, string $idempotencyKey): LmsSubmissionReceipt
        {
            throw new LogicException('Not called in this registry test.');
        }

        public function verify(User $user, string $assignmentId, LmsSubmissionReceipt $receipt): LmsSubmissionReceipt
        {
            throw new LogicException('Not called in this registry test.');
        }
    };
    $registry = new LmsAdapterRegistry([$adapter]);

    expect($registry->available())->toBe(['canvas' => 'Canvas'])
        ->and($registry->forPlatform('canvas'))->toBe($adapter);
});

test('submission page keeps manual recording available without a verified LMS adapter', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('submissions.index'))
        ->assertOk()
        ->assertSee('Konektor LMS belum dikonfigurasi')
        ->assertSee('Catat pengumpulan manual')
        ->assertSee('belum mengirim atau mengklaim verifikasi LMS');
});
