# Research needs more than an answer

By Bill Herald

Imagine having two notices about the same workshop. One says it starts at 10:00, and the other says 11:00. A useful research system should show where each statement came from, keep the disagreement visible and help you decide what needs checking. A polished answer that quietly chooses one time can create more work than it saves.

I am preparing the public PLOS v2.1 release with that practical purpose in mind. PLOS is a personal information and automation application built around source-backed work, configured tools and reviewable decisions. I want someone looking at the public project to see how the work is handled, rather than having to infer its usefulness from a feature list.

The application includes a web interface for knowledge, research and review, with workflow and scheduling surfaces for recurring work. The operator supplies the permitted documents, model routes and optional services. The public examples use fictional material. My private records, photos, credentials and deployment bindings are not part of the release.

Research starts with the sources. Search can locate relevant material, but a short preview is not the whole document. PLOS has an exact document-window reader that uses character offsets and returns where to continue. That gives the next step a way to inspect more of the stored text instead of treating the first matching passage as the complete evidence. The reader operates on the current stored document; I would not describe separate reads as an immutable snapshot.

Source references have their own limits. PLOS distinguishes a bound source from a usable locator and from a document with incomplete reference information. That makes a missing connection visible. A reference being ready to cite does not establish that the source is correct. In the workshop example, both notices can be properly referenced and still disagree.

I also want the original material to remain available when AI helps with the work. PLOS records source and chunking fingerprints so a changed source can be distinguished from a changed indexing procedure. Its conversation code retains the original provider messages and checks tool-call order. If the selected context is too small, the system does not silently remove material to make the request fit. An explicit summary has to stay connected to the original history and cannot conceal unfinished tool actions.

That same care is needed when research becomes an operation. A slow request, a disconnected client or a lost acknowledgement can leave uncertainty about whether work happened. Retrying without checking can create a second note, another notification or another job. PLOS keeps durable action identities and execution ownership so an existing result can be inspected without automatically repeating the action. An expired lease alone is not enough to conclude that the work stopped.

Optional model judgments belong inside these rules. Typed judgments can help with selection or ranking, but the application checks the registered task, permitted data and network policy before sending a request. If that optional route is unavailable or produces an invalid result, the existing permitted workflow continues. A model response does not grant authority to edit private evidence or publish a finding.

Skills and MCP tools make the methods easier to reach. A skill describes a bounded way to work, and a tool advertises its arguments and result contract. The installed registry and action gates still decide what is allowed. Copying a skill into a folder does not give it permission to approve facts, contact someone or run a private operation. Public examples should make that distinction easy to inspect.

Offline routing is another part of the method. Local and external routes need explicit configuration, and optional hardware paths need their own qualification. The portable controller material explains admission and recorded outcomes with examples; it does not make every machine or model a supported runtime. I want the source and its requirements visible so someone can make a reasonable decision about what to try on their own installation.

The included controller example inspects disabled fictional configuration. It does not run inference or start a server. Windows runtime checks still require the appropriate tools and local qualification; a multi-host diagram does not establish automatic fleet scheduling.

For a practical nightly process, I am including two fictional notices, an inactive retrieval-stage workflow and a separately labeled plan for the complete pipeline. The intended sequence is to preserve sources, retrieve evidence, prepare a proposed summary and get review. Optional Joplin output and a short Pushover status come afterward, using privately configured destinations. A queued note write is still pending. Reading a note from the server does not prove that a phone has synced it. The final report should reflect those states accurately.

This is also where testing needs plain language. A native definition can pass structural and integrity checks while still needing an installed database, admitted model route and prepared corpus. A synthetic test can verify an error boundary without proving research accuracy. The public documentation separates those checks from actual model runs and downstream delivery. I have not added a research-quality or performance claim where there is no measured result.

The useful parts of PLOS can be studied one at a time: complete source reads, reference readiness, preserved evidence, guarded execution, private diagnostics and human review. My purpose is to make those parts understandable and available with safe examples. For someone evaluating the project, I would start with the two contradictory notices. Follow the references, inspect the complete text and check what remains unresolved. That is a better starting point for research than accepting an answer because it sounds finished.
