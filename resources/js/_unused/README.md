# resources/js/_unused

This directory is intentionally empty.

It used to quarantine twelve TypeScript (.tsx) components that had been set aside as unused.

They were removed in commit c5f9e619. The repo-hygiene tests now require that no .ts or .tsx file survives under resources/js.

To recover one from history:

    git show c5f9e619^:resources/js/_unused/components/AuthCard.tsx
