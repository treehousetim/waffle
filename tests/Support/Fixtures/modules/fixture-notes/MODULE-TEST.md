# Fixture notes: what working means

## Desired outcomes

1. Signed out, reading notes answers `401`. (1)
2. A note of 256 characters is refused with `422`. (2)

## How to test

A feature test through the router for each outcome.

## Definition of done

`./tools/test.sh --group=fixture-notes` is green.
