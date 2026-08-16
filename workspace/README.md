# The CFP inbox

This directory is what the demo's MCP **client** advertises as a workspace root
(`App\Mcp\Client\WorkspaceRootsProvider`), and what the `import_proposals_from_roots`
tool on the `organizer` server scans once the client answers `roots/list`.

Every `*.proposal.md` file here is one submission: the first Markdown heading
becomes the title, the rest the abstract. Drop another one in and re-run the
tool — it skips titles that are already in the database, so it is safe to
repeat.

    php bin/console mcp:client:debug regression organizer_stdio
    php bin/console app:mcp:regression organizer_stdio

Roots were deprecated in protocol revision 2026-07-28 (SEP-2577) in favour of
passing paths as tool arguments. They are demonstrated here because hosts,
including Claude Desktop, still speak them.
