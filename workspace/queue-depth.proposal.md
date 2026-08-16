# What Your Queue Depth Is Telling You

A queue that never empties and a queue that empties instantly are both broken,
in opposite ways. This talk reads the shape of a backlog: what steady growth,
sawtooth patterns and sudden cliffs each say about the producers, the consumers
and the work in between.

We instrument a Symfony Messenger setup end to end, then break it on purpose —
a slow handler, a poison message, a consumer that dies quietly — and watch what
each failure looks like from the outside, before anyone has read a log.
