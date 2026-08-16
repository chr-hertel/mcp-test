# Schema-First Tools for Language Models

The input schema is the whole interface a model has to your application, and
most of the failure modes people blame on the model are really failures of that
schema: an argument the model cannot guess, an enum written as free text, an
error message with nothing actionable in it.

Using MCP as the vehicle, this talk works through what a good tool signature
looks like in PHP — types that carry meaning, constraints that fail early,
descriptions written for a reader who cannot ask a follow-up question — and
what it costs to get it wrong.
