<?php

declare(strict_types=1);

namespace MailerPress\Core\Migration;

\defined('ABSPATH') || exit;

interface MigrationSourceInterface
{
    public function getKey(): string;

    public function getLabel(): string;

    public function getDescription(): string;

    public function getIcon(): string;

    public function getIconSvg(): string;

    public function isDetected(): bool;

    public function getDetectionDetails(): array;

    public function getEntityTypes(): array;

    public function estimate(array $entityTypes = []): array;

    public function createChunks(int $runId, array $entityTypes, array $options, MigrationRepository $repository): array;

    public function processChunk(object $run, object $chunk, MigrationRepository $repository): array;
}
