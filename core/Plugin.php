<?php

declare(strict_types=1);

/**
 * A data source of the hub. The hub creates each plugin with its own
 * section of the settings file. See docs/specs/2026-10-31-hub-split-design.md.
 */
interface Plugin
{
    /**
     * API routes of the plugin: exact path => handler. A handler is
     * fn(Request $req, int $now): Response. Paths start with /api/<plugin>/.
     *
     * @return array<string, callable(Request, int): Response>
     */
    public function routes(): array;

    /** Data the dashboard may see. It is sent as part of /api/config. */
    public function publicConfig(): array;
}
