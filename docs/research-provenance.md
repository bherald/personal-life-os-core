# Research And Provenance Notes

This public register credits upstream projects, standards and research that informed PLOS. Recorded revisions and license signals identify historical research references; they are not declarations that a runtime is installed, enabled, qualified or measured. Dependency locks, license snapshots and the release notices identify what is actually shipped.

## Adoption and notice rules

Use the exact component license or another lawful basis before copying protected source, prompts, schemas, tests, assets or documentation. Public visibility and a public fork do not supply a blanket reuse license. Preserve copyright, permission, attribution, modification and applicable source-availability obligations.

GPL/AGPL and restricted/component-specific terms require review against the actual installation, distribution and network-use plan. Facts and unprotected ideas can inform independently authored code; a citation does not grant permission to copy paper text, datasets or model artifacts. Keep model/weight, code, dataset, documentation and service terms separate.

[THIRD_PARTY.md](../THIRD_PARTY.md), [NOTICE.md](../NOTICE.md), [LICENSES](../LICENSES), [reference rules](clean-room-references.md), [model/runtime terms](model-runtime-license-map.md) and the package license snapshots remain part of release review.

## Referenced project register

The following keeps recorded upstream identities and credits, without local execution receipts or deployment conclusions.

| Project or standard | Recorded upstream terms, revision or citation |
| --- | --- |
| Laravel framework and first-party Laravel tools | Direct Composer/npm dependencies; preserve lockfile/license metadata |
| OpenAI Whisper and robust speech-recognition paper | MIT repository revision `04f449b8a437f1bbd3dba5c9f826aca972e7709a`, and Radford et al., arXiv:2212.04356v1 |
| whisper.cpp | MIT revision `080bbbe85230f624f0b52127f1ae1218247989f9` |
| Open ASR Leaderboard | Apache-2.0 revision `b2dada0b970cb3eaa8dca8a755345234113fc84c`, and Srivastav et al., arXiv:2510.06961v4 |
| Laravel AI SDK | MIT `v0.7.2`; recorded branch revision `6ca3e1b8199a9ba9434a7ec1951fa3394b78be3d` |
| Vercel AI SDK | Apache-2.0 `v7.0.29`, revision `341616a326e6a210524d92d546e30af474e2aa6a` |
| Laravel PAO | MIT recorded revision `7896827f65c0590ce4130a7c6aca2154ef050f08`; recorded package reference released `v1.1.2` revision `41b3c61ebeddce52a446afe6d21e0b02983fb2f6` |
| Laravel MCP | MIT `v0.8.2` plus recorded revision `3d365d5db3493c806d190f3404cd7431634ca4e1` |
| Snyk Agent Scan | Apache-2.0 revision `30307337c25e892acb59421528721fd22e628e80` |
| MCP Security Bench | Cohen et al., *MCP Security Bench*, arXiv:2510.15994v2, revised 2026-03-24 |
| Microsoft Agent Governance Toolkit | MIT revision `a3b327de2648e7cdcc4bfda2f1cf7e69f7b59e20` |
| A2A Protocol | Linux Foundation Apache-2.0 `v1.0.1`, adopted revision `3303592588e388e62e0f69f701af531d2f4e3991`; recorded main `af112d9491c1fd4b2a568ac65755af4a62790490`, changes CODEOWNERS only |
| Execution-control invariants | Liu, *From Tool Connection to Execution Control*, arXiv:2606.29073v1, published 2026-06-27 |
| Prism PHP | MIT `v0.100.1`, revision `5d6cc65b80b19cf3f22744703ac0c727b68cdca8` |
| Ollama structured outputs | MIT revision `8a0016f826305b0672daaf02c6c55a34f844bba3` with the official structured-output and `/api/generate` documentation |
| Ollama tool calling and Qwen agent/coder guidance | Ollama MIT documentation/runtime, Apache-2.0 Qwen-Agent revision `31a4d36d123688581a9e9744427272b33ce940e0`, and Apache-2.0 Qwen3-Coder revision `33bc6aabd7791ad7b32f7e92104f11f2359ba890` |
| NVIDIA tegrastats and jetson-stats | NVIDIA's official Jetson Linux `tegrastats` format documentation and AGPL-3.0 `rbonghi/jetson_stats` revision `3c1ba9ac49a1307c9d7c53646bb70ba8c16b8759` |
| llama.cpp grammars | MIT revision `b15ca938ad00aa6b3ee6c2edda7363fd02826b18` |
| Dify | Dify Open Source License main revision `96e34e7b24a2f6b7acabb500ef847443405f4b59` |
| Immich | AGPL-3.0 `immich-app/immich` revision `f18065b3c2c0c3a7ad82b8a3c91ad3e82da3a383` |
| n8n | Sustainable Use License 1.0 head `64318a810df1cc7fe27470db9f9b1930c6d12e20`; `.ee` paths require a separate Enterprise License |
| AnythingLLM | MIT `v1.15.0`, revision `70e0d2eb1dcb08cbb18a44b927d94f8667f57a7f` |
| Flowise | `flowise@3.1.3`, revision `09f099dbfda179e439ec677d06ad737375899eef` |
| Microsoft GraphRAG | MIT `v3.1.0`, revision `7fc6607edda3d387d23e52ededbf8a75b6730f97` |
| A-RAG | Du et al., arXiv:2602.03442v1, plus the MIT-declared repository revision `a44de6b2216bf6791979c4b6ac4ae106212fa1a6` |
| PM-Bench | Liu and Gabriel, CC BY 4.0 arXiv:2607.12385v1, COLM 2026, plus public PMBench repository revision `e1093c470c8981daf522d4ef047a7c3a71e077d7` |
| Onyx | Community edition MIT `v4.3.4`, revision `cf20127d3f3738c9511e8c2c0c642386110bd341`; enterprise paths are separately licensed |
| Open WebUI | `v0.10.2`, revision `ecd48e2f718220a6400ecf49eafd4867a38feb10` under the Open WebUI License with branding restrictions |
| R2R | MIT `v3.6.5`, revision `191501190864e0762b9084ba96d066372456012c` |
| DSPy | MIT `3.2.1`, revision `29448ae12756abdd14bd8796c819247ebb83673c` |
| LangMem | MIT `0.0.30` at revision `c01e273b94aa4c06e41d0ed1ccce0db17de2bc11` |
| DeerFlow | MIT revision `de55982c5acd6edf5ccaa1a3a1d20a65185325ee`; delivery fix `2a7469cdbca578bcf984ffcfa56f733f3a8a5270` |
| GEPA | MIT `v0.1.1`, revision `b4dbb55b7601dac448cdb836d5a401ca7d9eb920`, and Agrawal et al., arXiv:2507.19457 |
| VISTA / Reflection in the Dark | Liu et al., ACL 2026, DOI `10.18653/v1/2026.acl-srw.8`, CC BY 4.0 |
| Aider | Apache-2.0 revision `5dc9490bb35f9729ef2c95d00a19ccd30c26339c` |
| gstack | MIT revision `a3259400a366593e0c909dd9ac3e59752efd2488`; recursive-delete fix `ea648b7dffc62e3e1ea81e756b0cbaff145b0f8c` |
| Cline, Roo Code, and Zoo Code | Cline Apache-2.0 revision `a41129a5dbda29c4e6b84968a2a798039ba32ab3` and Zoo Code Apache-2.0 revision `c39535e3949e6b7756be7489bf3f755cecd59ad0`; archived Roo Code remains Apache-2.0 `v3.54.0` at `27001b2b5aa47b65e8a6ba1914e0f4216be0ebb0` |
| SWE-agent / Agent-Computer Interface | MIT revision `1132b3e80a45487ce8423f75d0e180874bf84caa`, plus Yang et al., NeurIPS 2024, arXiv:2405.15793 |
| SWE-bench, SWE-smith, and Terminal-Bench | SWE-bench MIT revision `f7bbbb2ccdf479001d6467c9e34af59e44a840f9` with Jimenez et al., ICLR 2024; SWE-smith MIT revision `9b74ac08118a85c39c356802f7961893af73e07f` with Yang et al., NeurIPS 2025, arXiv:2504.21798; canonical `harbor-framework/terminal-bench` Apache-2.0 revision `d28711d0da2675d0bb1d56de45ae5df6082438a3` with Merrill et al., ICLR 2026, arXiv:2601.11868; |
| CodeScaleBench and MigrationBench | CodeScaleBench Apache-2.0 revision `8d5f3c876c28a5033634facf42c21da9ebc6fcd6`; its current license and public README were rechecked after the license-file delta |
| FEA-Bench | MIT repository revision `fb3c11274796f6057bf447410540fcf2fa1d90b1` and CC-BY-4.0 ACL 2025 paper DOI `10.18653/v1/2025.acl-long.839` |
| JSONSchemaBench | Geng et al., *JSONSchemaBench: A Rigorous Benchmark of Structured Outputs for Language Models*, arXiv:2501.10868v3, and unchanged `guidance-ai/jsonschemabench` revision `ba103c73756198dd9b149ddc7db7867da7a077f6` |
| OpenAI Evals and reproducible evaluation | MIT `openai/evals` revision `8eac7a7de5215c907fbddc30efdaf316913eccdd`, its build/custom-eval documentation, and Biderman et al., arXiv:2405.14782 |
| Splink and compound LLM entity matching | Unchanged MIT `moj-analytical-services/splink` revision `4774c889141ddff0b7a80fd3a1dbeaf6699419c0` plus Wang et al., arXiv:2405.16884v3 |
| Ancestry Agent and historical person disambiguation | MIT `javaberlin/ancestry-agent` revision `ff76b20629569f1db488fa2b027f9d2d6a87db80` plus Arora et al., EMNLP 2024, DOI `10.18653/v1/2024.emnlp-main.355` |
| Immich face/person review | AGPL-3.0 `immich-app/immich` revision `2cdbd0d00f9597d99c9767f7224af1e56e9b79f2` with the official facial-recognition documentation |
| Vue | Direct npm dependency; preserve package-manager metadata |
| Tailwind CSS | Direct npm dependency; preserve package-manager metadata |
| MySQL, MariaDB, PostgreSQL, Redis-compatible service | Operator-installed or Docker-provided services; document supported versions and image/license choices |
| Apache Tika | Operator-installed service; document setup and upstream terms |
| Docling | MIT code revision `e548307e8d32bb197c787b1f2998a87bafbc743b` with Auer et al., arXiv:2408.09869, and Livathinos et al., arXiv:2501.17887 |
| Unlimited-OCR | MIT code revision `528fca4e2161e23231d05666a6d35155dcb1957e` and MIT Hugging Face model revision `ee63731b6461c8afcdcc7b15352e7d2ffecc2ead` with the arXiv:2606.23050 paper |
| olmOCR | Apache-2.0 revision `f7cfe4c22098b154c76b6ec950d1c0a464eecf8d` |
| OmniDocBench | Apache-2.0 code revision `2b161d010d2e3aff77a0edef359ea3a6411d23cd` |
| OCRBench v2 / MultimodalOCR | MIT code revision `8b24ed51d3cf810a470b141ed28fb50074cd5680` with Liu et al., arXiv:2501.00321 and the NeurIPS 2025 dataset-and-benchmarks paper |
| Nextcloud | AGPL service revision `56d578ce42ed2cf879485a9f6f82ebcdc847423d` |
| Thunderbird | MPL software/tool boundary; PLOS should document local integration points, not bundle Thunderbird code |
| SearXNG | AGPL-3.0-or-later revision `b72a87676f119c691e7721b22b5fdc1b32c427e3` |
| Home Assistant Core | Apache-2.0 revision `e9be62fe77f221bf315411ec4d6514102a4f6c4e` |
| Gramps | GPL-2.0 revision `a32b463b1629f4385b8487024a6300e3602628d6` |
| Gramps Web | AGPL-3.0 revision `7a62d0f9aa9760503cbcf1f14c64391b878515f9` |
| webtrees | GPL-3.0 revision `18ed0b2a0f01264149ccffb9e25b6a5a64afb09c` |
| AI Genealogy | MIT revision `7b7dd3fe2f49729f43b19ff41492e653604e5ef8` |
| PhotoPrism | AGPL-signal revision `0cecb5287fb5867c1447876abb21572c1fe685bf` |
| LibrePhotos | MIT revision `135b87203c44d9461415c4931eb9b340c2abf6ce` |
| Joplin | Multi-component revision `f410aced1e1d0d052369830d122fec6f3f27cdc2` |
| Topola | Apache-2.0 package-manager dependency; preserve lockfile/license metadata and avoid vendoring modified upstream source without notice review |
| HyperFrames | Apache-2.0 release `v0.7.60`, main revision `b8a44e49c92ea03b3a3769725e1f9ab951e581c9` |
| Model Context Protocol TypeScript SDK/servers | Recorded MIT v1 and Apache-2.0/MIT transition in later source; consult final package snapshot for the actual dependency versions. |
| MCP 2025-11-25 Tools specification | Specification/reference use |
| FastMCP | Apache-2.0 revision `67e8448389c5149bb7f71a54323bbd00e3c32b86` |
| AG-UI Protocol | MIT recorded revision `8cca436fcf60876de92eeb64aa4f667c456e2da9`; adopted behavior revision `0e35504f79f497d54a745decbba170b242f00dd8` |
| LangGraph | MIT `langchain-ai/langgraph` revision `49ae27c2ae983cfb92091b0dea9f7bc37a716479` |
| OpenHands Software Agent SDK | MIT `OpenHands/software-agent-sdk` revision `51c102b9c0348bbdd4e6a84b1ac4199e0d77f827` |
| OpenHands Platform | MIT non-enterprise code at `All-Hands-AI/OpenHands` revision `5adf4dac2d9bf07605d51b5746891d457bd23076` with Liu, arXiv:2606.29073 |
| Goose | Apache-2.0 `aaif-goose/goose` revision `b56566ad781e0dd69f2a3eb6ac60cd489dcbcf23` |
| Microsoft Agent Framework | MIT revision `f4e49958f3c7a30b78991a894080be397044e698` |
| Microsoft MCP Gateway | MIT revision `6f1b11afa3a8c852393e37c1105a1d56f89e2b59` |
| Playwright MCP | Apache-2.0 `microsoft/playwright-mcp` revision `55679f5f3d4b4f3e2534ec0ce2fc5683ba2eaf3f` |
| MCP Inspector | Official 1.0.0 revision `ac3c1a122a5e072a200c99869fc0cd8bfa660ece` under its Apache-2.0/MIT transition and CC-BY-4.0 documentation posture |
| Agent Skills | `agentskills/agentskills` revision `38a2ff82958afee88dadf4831509e6f7e9d8ef4e`; code Apache-2.0 and documentation CC-BY-4.0 |
| Matt Pocock Skills | MIT `mattpocock/skills` revision `9603c1cc8118d08bc1b3bf34cf714f62178dea3b` |
| NVIDIA SkillSpector | Apache-2.0 `NVIDIA/SkillSpector` revision `8f534e2951e0b7d0b8fb8e84832cd3605f95c032` |
| NVIDIA Agent Skills | Apache-2.0 code and CC-BY-4.0 documentation at `NVIDIA/skills` revision `80a66a7a45c5f696a0a539154cc8b172f6b1cf1f` |
| Gramps MCP | MIT `v1.0.1`, revision `f515a2ece87e9bf13afb87eef33fdd13df87889e` |
| GEDCOM LLM Safety | MIT `labourdayos-afk/gedcom-llm-safety` revision `5af40e6973d155b789de271642db1cd8c038ec69` |
| Label Studio | Apache-2.0 `HumanSignal/label-studio` revision `6e228af069db3ea81d4d20d22f5009a61ddb6f2d` |
| Argilla | Apache-2.0 `argilla-io/argilla` revision `5338519accb13ae422f8bf9c0642651c249c49af` |
| cleanlab | Apache-2.0 `cleanlab/cleanlab` revision `750625747de1b26d8530954f51f0530bd0b51d3c` |
| ActiveLab: Active Learning with Re-Labeling by Multiple Annotators | Goh and Mueller, arXiv:2301.11856v1, official metadata |
| Google AI Edge Gallery | Apache-2.0 `google-ai-edge/gallery` revision `ac4f6865820c20267a77d0dd67d753110d018fc1` |
| Graphlit MCP server | Package metadata reports MIT; Graphlit service/API terms remain separate and should be optional in public installs |
| Nextcloud MCP server | Package metadata reports MIT; Nextcloud server itself remains an AGPL external service boundary |
| pgvector | Operator-installed database extension; cite and document as runtime dependency |
| Ollama | Ollama code is MIT, but model weights served by Ollama have separate terms |
| OpenTelemetry GenAI Semantic Conventions | Apache-2.0 `open-telemetry/semantic-conventions-genai` revision `647791f1ad23fd7c427dce4a984b3efee40961fc` |
| OpenTelemetry Protocol Protobuf | Apache-2.0 release v1.10.0 revision `ca839c51f706f5d53bfb46f06c3e90c3af3a52c6`; monitored main `6d0d33ba5a38353569e2fcb9dd7abce2aa608175` |
| AgentTrace | AlSayyad, Huang, and Pal, arXiv:2602.10133v1 under the arXiv non-exclusive distribution boundary |
| OpenInference | Apache-2.0 `Arize-ai/openinference` revision `5d5efd0e1217d84d3b899cbed3a40f9c606870c0` |
| Phoenix | Elastic-2.0 revision `fc9978cf00cd0cefb7ce99b87510c7a16078827c` |
| AgentOps: Enabling Observability of LLM Agents | Dong, Lu, and Zhu, arXiv:2411.05285 (2024) |
| Graphiti | Apache-2.0 `getzep/graphiti` revision `5e2be0faf7038a5b40e700d757b2c337e96b3a05` |
| Engram and Less Context, More Accuracy | AGPL-3.0 `ly-wang19/engram` release `0.1.0` revision `ed0e53a8b1626158282afe3d0ec252d52fe17749` and CC-BY-4.0 Wang, arXiv:2606.09900v1 |
| Mem0 and Memory Benchmarks | Apache-2.0 `mem0ai/mem0` revision `739534c0a3232e4b5ae6b4349ae4e50fc00df614`, plus Apache-2.0 `mem0ai/memory-benchmarks` revision `4b61c5d31b9c668a12b4f5e78064248a02c82d2b` |
| MemSyco-Bench | MIT `XMUDeepLIT/MemSyco-Bench` revision `c31e2c85ee8cc3c6f643587b8a6f4b5ad5eb3bf6` and Xiang et al., CC-BY-4.0 arXiv:2607.01071v2 |
| MemLineage | Ouyang and Hou, CC-BY-4.0 arXiv:2605.14421v1 |
| MemoryAgentBench | Hu, Wang, and McAuley, arXiv:2507.05257v4, revised 2026-06-28 |
| Letta and Cognee stateful memory | Apache-2.0 Letta revision `b76da9092518cbaa2d09042e52fdcbde69243e18` and Apache-2.0 Cognee 1.3.0 revision `252f2c3efb184533a0955e31e83a28ea7db9813d` |
| AMA-Bench | MIT `AMA-Bench/AMA-Bench` revision `ddfd319e0be33424288c13806f1eafc63e625b59` and Zhao et al., arXiv:2602.22769 |
| LongMemEval-V2 | Apache-2.0 `xiaowu0162/LongMemEval-V2` revision `be15ea6e995462f3391c1a610892df3f67dfa7bd` and Wu et al., arXiv:2605.12493v1 |
| Agent Memory systems characterization | Omri et al., arXiv:2606.06448v1 |
| MemoryData / Agent-Native Memory System | `OpenDataBox/MemoryData` revision `bdbe698f776d921ac791d1b07c0a7fc65a8bb4bb` and Zhou et al., arXiv:2606.24775v1 |
| Useful Memories Become Faulty When Continuously Updated by LLMs | Zhang et al., arXiv:2605.12978v1 |
| Promptfoo | MIT `promptfoo/promptfoo` revision `7b5a68ba2d0ffc36622faa16bab7dac6da7637bd` |
| OWASP CSV Injection | OWASP Foundation, `https://owasp.org/www-community/attacks/CSV_Injection`, CC-BY-SA-4.0 unless otherwise specified |
| OWASP Logging and Logging Vocabulary Cheat Sheets | CC-BY-SA-4.0 `OWASP/CheatSheetSeries` revision `ae0b3f22f5b24381455f742af9b5fa84063ad770` |
| Git checkout and clean contracts | GPL-2.0-only `git/git` revision `d35c5399e3e54ac277bb391fc2f6be3e816d312b` |
| rsync delete and dry-run contracts | GPL-3.0-or-later `RsyncProject/rsync` revision `f5fa55672db9c65dffb8698eeab3173a93692e16` |
| Capistrano release directory structure | MIT `capistrano/capistrano` revision `6007a972497ce7d05415b78dbba451c89438f996` |
| Reproducible Builds | Lamb and Zacchiroli, IEEE Software, arXiv:2104.06020v1 |
| nikic PHP-Parser | Direct BSD-3-Clause Composer dependency 5.8.0 revision `044a6a392ff8ad0d61f14370a5fbbd0a0107152f`; recorded main `fbd47f7ebcbb450138d92642a0a53b72a5285dda` |
| PSR-3 Logger Interface | Existing MIT `psr/log` 3.0.2 revision `f16e1d5863e37f8d8c2a01719f5b34baa2b714d3` |
| Privacy-Preserving Redaction of Diagnosis Data through Source Code Analysis | Kip et al., SSDBM 2023, DOI `10.1145/3603719.3603734`, arXiv:2409.17535v1 |
| Towards Secure Logging | Chen et al., FSE 2026, arXiv:2604.20211v1 |
| Safer Reasoning Traces | Ahrend et al., arXiv:2603.05618v1 |
| Monolog exception normalization | Existing MIT Composer dependency Monolog 3.10.0 revision `b321dd6749f0bf7189444158a3ce785cc16d69b0`; recorded main `57eb1028342134e701e77c617565d51b6e5a2a53` |
| OpenTelemetry exception log semantic conventions | Apache-2.0 `open-telemetry/semantic-conventions` 1.43.0 revision `436fa25713048054e035896c14a72139f8638ea8` |
| Quieting the Static | Liargkovas, Panourgia, and Spinellis, *Quieting the Static: A Study of Static Analysis Alert Suppressions*, arXiv:2311.07482v1, submitted 2023-11-13 |
| Semgrep | LGPL-2.1 release 1.170.0 revision `1f15ff4eb9f56f5a3b6a9afb958bdc82b5b6bcb0` plus official documentation |
| SARIF 2.1.0 | OASIS SARIF TC repository revision `ed71d4f62db866ce3698a08a5ec3f7f2e775545d` under the repository's OASIS policies and RF on RAND Terms Mode |
| Community Anthropic Cybersecurity Skills | Apache-2.0 `mukul975/Anthropic-Cybersecurity-Skills` revision `673da1f3b0b7be34ffc9624ef3858fe45f1c3bed` |
| DeepEval | Apache-2.0 `confident-ai/deepeval` revision `58c9ef78a4634ba119c7d2cc145f5cf9aeb24524` |
| AgentLens | Apache-2.0 `agent-lens/agent-lens-bench` revision `54dff743a2a2fa06090827bd5548e00d51984c65` and Podivilov et al., arXiv:2607.06624v2 |
| LangChain AgentEvals and AgentEvals for OpenTelemetry | MIT `langchain-ai/agentevals` revision `946ad15b9f2ce9bc9843edd2bb773bd07d78ca7e` and Apache-2.0 `agentevals-dev/agentevals` revision `17bb6836fc68458eaac765a5439f4aaf0b8cbca0` |
| TraceProbe | Shu et al., *What Resolve Rate Hides: Trajectory Structure Diagnostics for Coding Agents*, arXiv:2607.06184v1, submitted 2026-07-07 |
| RigorBench | Meher Bhaskar Madiraju and Meher Sai Preetam Madiraju, *RigorBench: Benchmarking Engineering Process Discipline in Autonomous AI Coding Agents*, arXiv:2606.22678v2, revised 2026-06-29, and public repository revision `9cf76088577aab083164a02d1912125ce0f17771` |
| PROBE / Debugging the Debuggers | Somashekar et al., arXiv:2605.08717v2, revised 2026-06-05 and, under the arXiv non-exclusive distribution license |
| Claw-Eval | arXiv:2604.06132v3, revised 2026-05-07, used for facts and ideas under the arXiv distribution boundary |
| Towards a Science of AI Agent Reliability | Rabanser et al., CC-BY-4.0 arXiv:2602.16666v3, revised 2026-06-02 and accepted at ICML 2026 |
| ReliabilityBench | Gupta, CC-BY-4.0 arXiv:2601.06112v1, submitted 2026-01-03 |
| HAL Harness | Public archived repository revision `16bb03ebc11577fb5ea6dc8bb6c968387085e6aa` |
| Microsoft PromptBench | MIT archived `microsoftarchive/promptbench` revision `fcda538bd779ad11612818e0645a387a462b5c3b` after the former Microsoft URL redirected to the archive organization |
| Microsoft AgentRx | MIT `microsoft/AgentRx` revision `f228165bfec60a801fd5fedd9d8ffe0f9de0c69d` and Somashekar et al., arXiv:2602.02475v1 |
| TRAJECT-Bench | MIT `PengfeiHePower/TRAJECT-Bench` revision `2723fd890778dbfb6af9e3aa8ee1c22272979468` and He et al., arXiv:2510.04550v2 |
| OmniCoreAgent and Infinite Agentic Loops | MIT `omnirexflora-labs/omnicoreagent` release `0.3.9`, revision `60da57a6dacd3f7796fffd9d198c0a7420aa6aad`, and Hou et al., arXiv:2607.01641v1 |
| CLI Agent Failure Process and ToolMaze | Zhao et al., CC-BY-4.0 arXiv:2607.09510v1, and Zhu et al., CC-BY-4.0 arXiv:2606.05806v1 |
| Apple ToolSandbox | Apple `ToolSandbox` revision `165848b9a78cead7ca7fe7c89c688b58e6501219` and Lu et al., arXiv:2408.04682 |
| Procedure-Aware Evaluation | *Beyond Task Completion: Revealing Corrupt Success in LLM Agents through Procedure-Aware Evaluation*, arXiv:2603.03116v1 |
| MTEB | Apache-2.0 `embeddings-benchmark/mteb` revision `5594cc91d76bea60a014b22e6999c4d223af3037` |
| BEIR | Apache-2.0 `beir-cellar/beir` revision `ef83d29307061c65d04b035b4f4e7c18bd8374af` |
| RAGChecker | Apache-2.0 `amazon-science/RAGChecker` revision `6091f08c00e676e87a970f2aeb4a23a484746348` plus Ru et al., arXiv:2408.08067 |
| Haystack and Lost in the Middle | Apache-2.0 `deepset-ai/haystack` revision `5c474c5d8dd67eb3e8232ab244d75773c1cb0756` |
| LightRAG, LlamaIndex, and RAGFlow ingestion and tool-message provenance | MIT LightRAG revision `c294efbddc4990d1dfa58aeae5f385917ceb3a6f`, MIT LlamaIndex revision `dbdaf89dc66a6469081c9f8fddc9c1bf6c43d8a2`, and Apache-2.0 RAGFlow revision `7c698f8e4b0790c2ce2db73a9f46ded3f3f91296` |
| LightRAG task-custody recovery | MIT revision `72d6292972c793ebc1aeaae01f9a94fd19a42cce` |
| Gray and Cheriton leases | Gray and Cheriton, *Leases: An Efficient Fault-Tolerant Mechanism for Distributed File Cache Consistency*, SOSP 1989, DOI `10.1145/74850.74870` |
| Ollama Claude Channels delta | MIT revision `03d61e19255158fc90552537aaec843b3867c2e1` |
| Failure as a Process | Zhao et al., arXiv:2607.09510v1, CC-BY-4.0 |
| Controlled code-RAG chunking study | Wu, Gong, Jahangirova, and Zhang, arXiv `2605.04763v1`, 2026-05-06, under the arXiv non-exclusive distribution license |
| HiChunk and HiCBench | Lu, Chen, Qiao, and Sun, arXiv `2509.11552v3`, revised 2025-10-09, under the arXiv non-exclusive distribution license |
| Browser Use and browser-agent origin security | MIT `browser-use/browser-use` revision `dbc4d46e0ca59afbba020d456aa10d930279330c`; upstream security commit `6a275d3962c9bdde72a35a0ade38ce819683da78`; *Mind the Web: The Security of Web Use Agents*, arXiv:2506.07153; and *Agentic Browsers and the Same-Origin Policy*, Roesner and Kohlbrenner (2026) |
| ipaddr.js and SSRF hostname validation | Direct exact MIT npm dependency `ipaddr.js` v2.4.0, tag revision `61bd6dfd22a53c7d7c044aefe3acad4b0dd7f197` |
| mcp-agent streaming continuity and LLM serving recovery | Apache-2.0 `lastmile-ai/mcp-agent` revision `f62d849350816588b1c6294e7914bbe4d8b84072`; *P/D-Serve: Serving Disaggregated Large Language Model at Scale*, arXiv:2408.08147; and *LUMEN: Coordinated Failure Recovery for Distributed LLM Serving*, arXiv:2606.17787 |
| OpenAI Codex CLI structured execution, project-scoped guidance, MCP result fidelity, paginated durable history, resolved agent settings, and bounded skill selection | Apache-2.0 `openai/codex` recorded revision `0f44bca9154e056a32fde7a89026b4620599e6f2`; directly adopted fielded BM25 revision `a47c661ea9e226fe65e46cf9dbc5c5ed75c2c762` and character n-gram revision `c983a53f2035bb9b6e999b1355b0056946671e9e` |
| Model Context Protocol Servers / Everything | Apache-2.0/MIT code transition and CC-BY-4.0 documentation; preserve the full applicable upstream transition notice. |
| Opis JSON Schema | Apache-2.0 recorded revision `85d8523cc5cff5494efaf54112605290d394b67e`; recorded package reference `opis/json-schema` `2.6.0` revision `8458763e0dd0b6baa310e04f1829fc73da4e8c8a` |
| opencode developer agent controls | MIT `anomalyco/opencode` revision `17544802c38a4d35834275526ccf38be1cdcfbf4` |
| Qwen3 Embedding and Reranker | Apache-2.0 code revision `44548aa5f0a0aed1c76d64e19afe47727a325b8f` and official Apache-2.0 model revision `e61197ed45024b0ed8a2d74b80b4d909f1255473` |
| Piper TTS | `OHF-Voice/piper1-gpl` GPL-3.0 main `49fab2fd5c1511aadc8020524d8ea31cfbb5b238` |
| Kokoro ONNX and KittenTTS | Kokoro ONNX MIT code revision `98ea02a5692534c2ba496708e2f19de25028412b`; official `hexgrad/Kokoro-82M` model terms are Apache-2.0, and each voice must retain exact hash and terms; KittenTTS records Apache-2.0 developer-preview terms |
| NISQA and TTS quality papers | NISQA MIT code revision `fe84f0f252abec382b24367d5b22498a7ce34dbb`; supplied weights CC-BY-NC-SA-4.0; corpus terms source-specific |
| dlib and face_recognition | Permissive code dependency signals, but dlib model files stay external with checksums in `docs/FACE-RECOGNITION.md` |
| hdbscan | Permissive Python dependency; cite library/paper and keep package-manager install path |
| ExifTool, XMP, IPTC, MWG regions | Standards/tool compatibility, not copied application code |
| FamilySearch GEDCOM 7 | Apache-2.0 revision `6347d94c3f3104c4afd2ebc9bd293ae3fdc7b622` |
| mrmysql/youtube-transcript | Package metadata reports WTFPL; YouTube access still has service terms separate from code license |
| Ancestry and FamilySearch | Service access requires provider-specific terms and explicit authorization; no unsupported autonomous API or scraping promise. |
| MyHeritage and Newspapers.com | Provider terms and separately reviewed authorized integrations; no public default access promise. |
| FindAGrave, BillionGraves, WikiTree, LOC, NARA, Internet Archive | Prefer official APIs, public-domain archives, respectful rate limits, and provider-specific review where terms are unclear |
| codebase-memory-mcp | MIT `DeusData/codebase-memory-mcp` development revision `a688d85cc8fda6ef09b1ed4534f967faeef63cc3` |
| GNU grep | GPLv3-or-later upstream tool; not bundled source. |
| Repository Intelligence Graph | Cherny-Shahar and Yehudai, arXiv:2601.10112v1, 2026-01-15, CC-BY-4.0 |
| CORE-Bench | Zhang et al., arXiv:2606.11864v2, revised 2026-07-13, under the arXiv non-exclusive distribution license |
| SWE-Explore | Zhang et al., arXiv:2606.07297v1, 2026-06-05, CC-BY-NC-ND-4.0 |
| Evaluating AGENTS.md | Gloaguen et al., arXiv:2602.11988v2, revised 2026-06-23, CC-BY-4.0 |
| Configuration Smells in AGENTS.md Files | dos Santos et al., arXiv:2606.15828v4, revised 2026-06-19, under the arXiv non-exclusive distribution license |
| ContextBench | Li et al., arXiv:2602.05892v3, revised 2026-02-11, CC-BY-4.0 |
| RuBench | CC-BY-4.0 paper arXiv:2607.06411v1 and `eugeneshilow/rubench` revision `2849a1740cbd404552e460f82f339c1103244a7d` |
| arXiv API metadata | Official arXiv API documentation; identifier metadata and paper/full-text reuse terms remain distinct. |
| GitHub REST commit metadata | Official GitHub REST API version `2026-03-10` |
| Crossref REST metadata | Official Crossref REST documentation |
| Citation File Format | `citation-file-format/citation-file-format`, CFF 1.2.0, CC-BY-4.0 |

## Additional runtime/model research references

| Source | Recorded upstream revision and terms |
| --- | --- |
| [JustVugg/colibri](https://github.com/JustVugg/colibri) | Apache-2.0 development `417620d467b543b8979fceec55a88bcb9b33e2f6`. |
| [sqliteai/warp](https://github.com/sqliteai/warp) | Apache-2.0 `505e481b25080eee912588f9e5e688778666f179`. |
| [FareedKhan-dev/kimi-k3-in-c](https://github.com/FareedKhan-dev/kimi-k3-in-c) | Apache-2.0 `ff11dce858a2eb8a781224facdffd33a1fa48d25`. |
| [gavamedia/deltafin](https://github.com/gavamedia/deltafin) | MIT `441fbfd17568a883369bb15699f6410a9901c4bc`; K3-derived assets retain upstream terms. |
| [haiyan-ai-lab/adaptive-moe-inference](https://github.com/haiyan-ai-lab/adaptive-moe-inference) | Apache-2.0 `4c345d283482c9ce0db34c889a4a02f850180c49`. |
| [zeroextub-collab/wisp](https://github.com/zeroextub-collab/wisp) | MIT `2b26eebdcec644ba13d0b6f82d39357764ad0046`. |
| [onetoken-oss/K3Flight](https://github.com/onetoken-oss/K3Flight) | Preview `b4ad863efb6ae0f6371a6c2bbe4c96a63b79bd35`; separate binary/commercial terms. |
| [Maknee/Kimi-K3.cpp](https://github.com/Maknee/Kimi-K3.cpp) | `94410a784f4b12ee0260008dfb983fc46aa42752`; no clear repository license found. |
| [JustVugg/colibri Kimi K3 development runtime](https://github.com/JustVugg/colibri/commit/47a749d8225b815adc9622c964ba386639320973) | Apache-2.0 commit `47a749d8225b815adc9622c964ba386639320973`. |
| [Moonshot AI Kimi K3](https://huggingface.co/moonshotai/Kimi-K3/tree/9f62e4e9fffbd0a83ddd60e1c209d828994b3569) | Kimi K3 License model revision `9f62e4e9fffbd0a83ddd60e1c209d828994b3569`. |
| [Moonshot AI Kimi K3](https://huggingface.co/moonshotai/Kimi-K3) | Model revision `9f62e4e9fffbd0a83ddd60e1c209d828994b3569`; Kimi K3 License. |
| [sqliteai/WASTE](https://github.com/sqliteai/waste) | Apache-2.0 revision `df542092e1c17bc8a403ad25d3112d6f06e157ea`. |
| [llama.cpp K3 text branch](https://github.com/ggml-org/llama.cpp/pull/26185) | MIT branch revision `d5ab207564289fcb28460093e9d0bc4b4fe939af`; open PR #26185. |
| [Unsloth Kimi K3 GGUF](https://huggingface.co/unsloth/Kimi-K3-GGUF) | Model revision `3d4b61ab4b6789d401191c476cbb4567246db8f5`; derived weights remain subject to the Kimi K3 License. |
| [Deltafin](https://github.com/gavamedia/deltafin) | MIT revision `f590b7d3b7f0643998008aebf979a6f42c7fba59`; Moonshot's license remains applicable to fetched K3 material. |
| [kimi-k3-in-c](https://github.com/FareedKhan-dev/kimi-k3-in-c) | Apache-2.0 revision `85ab2cd901aa81b70caac7711f06864d594b8ff3`. |
| [Colibri gateway hardening](https://github.com/JustVugg/colibri/commit/edcdd4e36b2d0f0800b6f71c0e8c59b725ba8f66) | Apache-2.0 commit `edcdd4e36b2d0f0800b6f71c0e8c59b725ba8f66`. |
| [Physical-core OpenMP helper](https://github.com/JustVugg/colibri/commit/fd8fae93a08e95fca3674f9d0d75dcb91fe2a054) and [GLM integration](https://github.com/JustVugg/colibri/commit/948e7b134ed45ec04a6db96f347074454d8add5f) | Apache-2.0 commits `fd8fae93a08e95fca3674f9d0d75dcb91fe2a054` and `948e7b134ed45ec04a6db96f347074454d8add5f`; the latter merged in PR #791. |
| [Automatic-pin LRU preservation](https://github.com/JustVugg/colibri/commit/81ef1a9d278ada270929e0c398971bf08a094a86) | Apache-2.0 commit `81ef1a9d278ada270929e0c398971bf08a094a86`, merged in PR #815. |
| [Shard-specific doctor error](https://github.com/JustVugg/colibri/commit/e64b483dd44c0ac447753e82e45310f7c39c4ac4) | Apache-2.0 commit `e64b483dd44c0ac447753e82e45310f7c39c4ac4`. |

## Developer-agent source register

| Project | Public source | Recorded component terms |
| --- | --- | --- |
| OpenAI Codex CLI | https://github.com/openai/codex | Apache-2.0 license file. |
| Cline | https://github.com/cline/cline and https://cline.bot/ | Apache-2.0 project signal; service terms are a separate review surface. |
| Roo Code | https://github.com/RooCodeInc/Roo-Code | Apache-2.0 license file. |
| Aider | https://github.com/aider-ai/aider and https://aider.chat/ | Apache-2.0 project signal. |
| Continue | https://github.com/continuedev/continue and https://continue.dev/ | Apache-2.0 revision `d0a3c0b626b5bebc3bef4742eec05a0242be0bab`, reviewed 2026-07-16. |
| Laravel Boost | https://github.com/laravel/boost | MIT revision `70d0245f3b624f7565d9dcf61dead8da35e28a50`, reviewed 2026-07-16. |
| Local gateway and heterogeneous-runtime cohort | https://github.com/mostlygeek/llama-swap, https://github.com/mudler/LocalAI, https://github.com/kvcache-ai/ktransformers, and https://github.com/mlc-ai/mlc-llm | MIT llama-swap `6b5320de5dc7f6f9f3266afd37a707279be271ca`; MIT LocalAI `dc2cc4da439307d926d1c940eac666a62b7db485`; Apache-2.0 KTransformers `01fdfa609e731f0dc1c088e596ad189144a046bd`; Apache-2.0 MLC LLM `a2bcc5c86678b72a86b7aadc29b643a5ce63c747`; reviewed 2026-07-16. |
| Open Genealogy | https://github.com/DigitalArchivst/Open-Genealogy | CC-BY-NC-SA-4.0 signal revision `249db9e47662a69b6ed4fc84b7ef7e6387c81b33`, reviewed 2026-07-16; verify exact file-level terms before reuse. |
| OpenHands / Software Agent SDK | https://github.com/OpenHands/software-agent-sdk and https://docs.openhands.dev/sdk/guides/agent-stuck-detector | MIT at revision `51c102b9c0348bbdd4e6a84b1ac4199e0d77f827`. |
| OmniCoreAgent | https://github.com/omnirexflora-labs/omnicoreagent | MIT release `0.3.9`, revision `60da57a6dacd3f7796fffd9d198c0a7420aa6aad`; preserve the copyright and permission notice if substantial source is redistributed. |
| SWE-agent | https://github.com/swe-agent/swe-agent and https://swe-agent.com/ | MIT project signal. |
| mini-swe-agent | https://github.com/SWE-agent/mini-swe-agent and https://mini-swe-agent.com/ | MIT project signal. |
| Hermes Agent | https://github.com/NousResearch/hermes-agent | MIT at revision `3f2a389c7e1f1729cad91ae63c26fb08c7753c74`, reviewed 2026-07-15. |
| goose | https://github.com/aaif-goose/goose and https://goose-docs.ai/ | Apache-2.0 revision `b56566ad781e0dd69f2a3eb6ac60cd489dcbcf23`, reviewed 2026-07-16; AAIF/Linux Foundation transition noted by upstream. |
| LiteLLM | https://github.com/BerriAI/litellm, exact capability commit https://github.com/BerriAI/litellm/commit/477ef3a7e2cc02488d97692d3f870a3dd39f2c20, exact virtual-key logging fix https://github.com/BerriAI/litellm/commit/f31dacbcd4b20a932fac1b9aa865b7fdbaa0ffe3, and current reviewed revision https://github.com/BerriAI/litellm/commit/3f5ed5a9c8d651896a1caf37eb9867ddfb9dff32 | MIT outside the separately licensed `enterprise/` directory; current reviewed revision `3f5ed5a9c8d651896a1caf37eb9867ddfb9dff32`, reviewed 2026-07-16. |
| llama.cpp | https://github.com/ggml-org/llama.cpp/commit/f6f12e43fa869ef0e008b99ed97dc4006bbb8907 | MIT at revision `f6f12e43fa869ef0e008b99ed97dc4006bbb8907`; model files remain separately licensed by their publishers. |
| vLLM | https://github.com/vllm-project/vllm/commit/75bdad40b5e96517c7f7645ce3199a8557817e17 | Apache-2.0 at revision `75bdad40b5e96517c7f7645ce3199a8557817e17`, reviewed 2026-07-16. |
| Claw Code | https://github.com/ultraworkers/claw-code and https://claw-code.codes/ | MIT license signal from the current canonical GitHub repo; verify the exact revision and that adopted files are covered. |

## Required component and permission watch items

- A-RAG records MIT declarations in package/README metadata but no standalone LICENSE file; verify the exact covered files before reuse.
- MemoryData, PM-Bench repository, JSONSchemaBench repository, RigorBench, HAL Harness and Maknee/Kimi-K3.cpp have missing or unclear repository licensing in the recorded review. Keep them as factual/ideas references unless a lawful reuse basis is established; paper licensing and repository licensing are separate.
- K3Flight has separate binary/commercial terms. Kimi K3 models and converted derivatives retain the Kimi K3 License; an Apache/MIT engine does not relicense those weights.
- Deltafin adaptations retain the GAVAMEDIA copyright and MIT permission notice; derived model material retains its upstream model terms.
- Dify Open Source License, n8n Sustainable Use License 1.0 and separate Enterprise License, Open WebUI branding restrictions, and Flowise/Onyx/OpenHands/LiteLLM enterprise/component boundaries require exact component review. Do not replace these with a generic permissive label.
- Joplin is multi-component; preserve each adopted component license. Model Context Protocol sources include Apache-2.0/MIT transition text and CC-BY-4.0 documentation; retain the applicable full notices rather than assuming a package-wide OR choice.
- Open Genealogy CC-BY-NC-SA-4.0; SWE-Explore CC-BY-NC-ND-4.0; NISQA supplied weights CC-BY-NC-SA-4.0 with corpus-specific terms; Apple ToolSandbox Apple sample-code license and acknowledged component terms; and RuBench CC-BY-4.0 tasks/metadata versus separately licensed derived snapshots remain distinct watch items.
- BEIR datasets have source-specific licenses. arXiv non-exclusive distribution licenses are not general reuse licenses. Crossref factual metadata/CC0-generated data does not make copyrighted abstracts or full text freely reusable.
- Face/photo identity and configured-tree genealogy edits require operator review; third-party genealogy/media service access has independent permission and service-term boundaries.
- Runtime/model binaries, model weights, datasets, service images, credentials and local qualification evidence are not supplied by this reference register.

## Research citation backbone

Preserve author, title, venue/version and primary source when citing these references. Citation does not establish adoption or operational effectiveness.

- Otwell, T. and Laravel contributors. Laravel Framework: The PHP Framework for
  Web Artisans. https://github.com/laravel/framework and https://laravel.com/

- Laravel contributors. Laravel Horizon: dashboard and code-driven
  configuration for Laravel queues. https://github.com/laravel/horizon and
  https://laravel.com/docs/horizon

- Laravel contributors. Laravel Passport: OAuth2 server support for Laravel.
  https://github.com/laravel/passport

- Laravel contributors. Laravel Tinker, Pint, Pail, Sail, and Vite Plugin.
  https://github.com/laravel/tinker
  https://github.com/laravel/pint
  https://github.com/laravel/pail
  https://github.com/laravel/sail
  https://github.com/laravel/vite-plugin

- You, E. (2014-present). Vue.js: The Progressive JavaScript Framework.
  https://github.com/vuejs/core and https://vuejs.org/

- Tailwind Labs. Tailwind CSS. https://github.com/tailwindlabs/tailwindcss and
  https://tailwindcss.com/

- PostgreSQL Global Development Group. PostgreSQL. https://www.postgresql.org/

- Oracle and contributors. MySQL. https://www.mysql.com/

- MariaDB Foundation. MariaDB Server. https://mariadb.org/

- Redis/Valkey-compatible queue and cache service references:
  https://redis.io/ and https://valkey.io/

- Apache Software Foundation. Apache Tika. https://tika.apache.org/

- Nextcloud contributors. Nextcloud server and Docker image references.
  https://github.com/nextcloud/server and https://github.com/nextcloud/docker

- Mozilla. Thunderbird and Mozilla Public License 2.0.
  https://www.thunderbird.net/ and https://www.mozilla.org/en-US/MPL/2.0/

- SearXNG contributors. SearXNG metasearch engine.
  https://github.com/searxng/searxng and https://docs.searxng.org/

- Staufer, L., Feng, K., Wei, K., Bailey, L., Duan, Y., Yang, M., Ozisik, A. P.,
  Casper, S., & Kolt, N. (2026). The 2025 AI Agent Index: Documenting Technical
  and Safety Features of Deployed Agentic AI Systems. arXiv:2602.17753.
  https://arxiv.org/abs/2602.17753 and https://aiagentindex.mit.edu/

- Edge, D., Trinh, H., Cheng, N., Bradley, J., Chao, A., Mody, A., Truitt, S.,
  Metropolitansky, D., Ness, R. O., & Larson, J. (2024). From Local to Global:
  A Graph RAG Approach to Query-Focused Summarization. arXiv:2404.16130.
  https://arxiv.org/abs/2404.16130

- Sarthi, P., Abdullah, S., Tuli, A., Khanna, S., Goldie, A., & Manning, C. D.
  (2024). RAPTOR: Recursive Abstractive Processing for Tree-Organized
  Retrieval. arXiv:2401.18059. https://arxiv.org/abs/2401.18059

- Muennighoff, N., Tazi, N., Magne, L., & Reimers, N. (2022). MTEB: Massive
  Text Embedding Benchmark. arXiv:2210.07316.
  https://arxiv.org/abs/2210.07316

- Thakur, N., Reimers, N., Rucklé, A., Srivastava, A., & Gurevych, I. (2021).
  BEIR: A Heterogeneous Benchmark for Zero-shot Evaluation of Information
  Retrieval Models. NeurIPS Datasets and Benchmarks.
  https://arxiv.org/abs/2104.08663

- Ru, D., Qiu, L., Hu, X., et al. (2024). RAGChecker: A Fine-grained Framework
  for Diagnosing Retrieval-Augmented Generation. arXiv:2408.08067.
  https://arxiv.org/abs/2408.08067

- Liu, N. F., Lin, K., Hewitt, J., Paranjape, A., Bevilacqua, M., Petroni, F.,
  & Liang, P. (2024). Lost in the Middle: How Language Models Use Long
  Contexts. Transactions of the Association for Computational Linguistics, 12,
  157-173. https://aclanthology.org/2024.tacl-1.9/

- Zhang, Y., Li, M., Long, D., et al. (2025). Qwen3 Embedding: Advancing Text
  Embedding and Reranking Through Foundation Models. arXiv:2506.05176.
  https://arxiv.org/abs/2506.05176

- Formal, T., Piwowarski, B., & Clinchant, S. (2021). SPLADE: Sparse Lexical and
  Expansion Model for First Stage Ranking. SIGIR 2021. https://arxiv.org/abs/2107.05720

- Santhanam, K., Khattab, O., Saad-Falcon, J., Potts, C., & Zaharia, M. (2022).
  ColBERTv2: Effective and Efficient Retrieval via Lightweight Late Interaction.
  NAACL 2022. https://aclanthology.org/2022.naacl-main.272/

- Cormack, G. V., Clarke, C. L. A., & Buettcher, S. (2009). Reciprocal Rank
  Fusion Outperforms Condorcet and Individual Rank Learning Methods. SIGIR 2009.

- Carbonell, J., & Goldstein, J. (1998). The use of MMR, diversity-based
  reranking for reordering documents and producing summaries. SIGIR 1998.

- Malkov, Y. A., & Yashunin, D. A. (2018). Efficient and robust approximate
  nearest neighbor search using Hierarchical Navigable Small World graphs. IEEE
  TPAMI.

- Campello, R. J. G. B., Moulavi, D., & Sander, J. (2013). Density-Based
  Clustering Based on Hierarchical Density Estimates. PAKDD 2013.

- McInnes, L., Healy, J., & Astels, S. (2017). hdbscan: Hierarchical density
  based clustering. Journal of Open Source Software. https://joss.theoj.org/papers/10.21105/joss.00205

- King, D. E. (2009). Dlib-ml: A Machine Learning Toolkit. Journal of Machine
  Learning Research.

- Katz, A. (2023). pgvector: Open-source vector similarity search for Postgres.
  https://github.com/pgvector/pgvector

- Ollama Team. (2023). Ollama: Get up and running with large language models
  locally. https://github.com/ollama/ollama

- Model Context Protocol Contributors. Model Context Protocol specification,
  stable version 2025-11-25. https://modelcontextprotocol.io/specification/2025-11-25/server/tools
  and https://github.com/modelcontextprotocol/modelcontextprotocol

- Portkey, Inc. Portkey AI Gateway, MIT revision `669825cbe89ee51569918b8f78a9db486fd69dd4`. Provider trace and retry documentation: https://github.com/Portkey-AI/gateway and https://portkey.ai/docs/product/observability/traces

- MrMySQL. youtube-transcript. https://github.com/MrMySQL/youtube-transcript

- Paperless-ngx contributors. Paperless-ngx, GPL-3.0 revision `71557d7c648e68a74b2c9cd1d60d24e314ed4675`; consumer/configuration and generation-scoped source identity references. https://github.com/paperless-ngx/paperless-ngx and https://github.com/paperless-ngx/paperless-ngx/blob/dev/docs/configuration.md

- Graphlit. graphlit-mcp-server. https://github.com/graphlit/graphlit-mcp-server

- Abdullah MASHUK. nextcloud-mcp-server.
  https://github.com/abdullahMASHUK/nextcloud-mcp-server

- FamilySearch. FamilySearch GEDCOM 7 specification.
  https://gedcom.io/specifications/FamilySearchGEDCOMv7.html

- ExifTool tag references for XMP/MWG/IPTC-compatible face and person metadata.
  https://exiftool.org/TagNames/MWG.html and https://exiftool.org/TagNames/XMP.html

- IPTC Photo Metadata Standard. https://iptc.org/standards/photo-metadata/

- Adobe XMP specification index. https://developer.adobe.com/xmp/docs/

- Board for Certification of Genealogists. (2000). The BCG Genealogical
  Standards Manual. Use as the genealogy proof-standard citation in public
  genealogy articles.

- Sam-Bodden, B. (2026). What Context Does a Coding Agent Actually Need to Act? arXiv:2607.09691v1, CC-BY-4.0. https://arxiv.org/abs/2607.09691

## Complete public source index

All public source URLs from the input provenance register are retained below, including individual upstream commits, pull requests, model cards and standards. They are references rather than private build or deployment identities. Recheck the applicable source and component terms before relying on an old revision.

- https://github.com/JustVugg/colibri
- https://github.com/sqliteai/warp
- https://github.com/FareedKhan-dev/kimi-k3-in-c
- https://github.com/gavamedia/deltafin
- https://github.com/haiyan-ai-lab/adaptive-moe-inference
- https://github.com/zeroextub-collab/wisp
- https://github.com/onetoken-oss/K3Flight
- https://github.com/Maknee/Kimi-K3.cpp
- https://github.com/JustVugg/colibri/commit/47a749d8225b815adc9622c964ba386639320973
- https://huggingface.co/moonshotai/Kimi-K3/tree/9f62e4e9fffbd0a83ddd60e1c209d828994b3569
- https://huggingface.co/moonshotai/Kimi-K3
- https://github.com/sqliteai/waste
- https://github.com/ggml-org/llama.cpp/pull/26185
- https://huggingface.co/unsloth/Kimi-K3-GGUF
- https://github.com/JustVugg/colibri/commit/81f08a09e5651ce52616dc720f68810f9021c0be
- https://github.com/bherald/colibri/commit/cc5df232f763887fd8ce07a15c18c22aa71dd0bc
- https://github.com/bherald/colibri/commit/37c8fac9b5bdbc64eda71e927ba049e53a442966
- https://github.com/bherald/colibri/commit/83fc0a71b8cb2d3a128b8b4d47ab48731b05c247
- https://github.com/bherald/colibri/commit/74326b9d9182a0c69e7423586bbfe75f60aa48d7
- https://github.com/JustVugg/colibri/commit/db4ed612f650665d8943b8eb79905f4998482d50
- https://github.com/JustVugg/colibri/commit/926dde9af3b2f19faa1cb7d1e608de0ee8f44417
- https://github.com/ghostnumber7
- https://github.com/bherald/colibri
- https://github.com/JustVugg/colibri/pull/535
- https://github.com/JustVugg/colibri/pull/536
- https://github.com/JustVugg/colibri/pull/556
- https://github.com/bherald/colibri/commit/db5b46624b8892fcaa32fe135c514ebbb8d8d8ef
- https://huggingface.co/zai-org/GLM-5.2-FP8
- https://huggingface.co/mastouri/GLM-5.2-colibri-E8-IQ3-with-int8-mtp
- https://github.com/JustVugg/colibri/commit/edcdd4e36b2d0f0800b6f71c0e8c59b725ba8f66
- https://github.com/JustVugg/colibri/commit/fd8fae93a08e95fca3674f9d0d75dcb91fe2a054
- https://github.com/JustVugg/colibri/commit/948e7b134ed45ec04a6db96f347074454d8add5f
- https://github.com/JustVugg/colibri/commit/81ef1a9d278ada270929e0c398971bf08a094a86
- https://github.com/JustVugg/colibri/commit/e64b483dd44c0ac447753e82e45310f7c39c4ac4
- https://github.com/modelcontextprotocol/inspector
- https://github.com/modelcontextprotocol/inspector/commit/ac3c1a122a5e072a200c99869fc0cd8bfa660ece
- https://github.com/PrefectHQ/fastmcp
- https://github.com/PrefectHQ/fastmcp/commit/67e8448389c5149bb7f71a54323bbd00e3c32b86
- https://github.com/modelcontextprotocol/typescript-sdk
- https://github.com/modelcontextprotocol/typescript-sdk/commit/f60dff0674954ab516739f21ad9905349c8e9249
- https://github.com/HKUDS/LightRAG/commit/2f16530d032553a7f117b912493d92ce13158a2c
- https://github.com/ggml-org/llama.cpp/commit/571d0d540df04f25298d0e159e520d9fc62ed121
- https://github.com/anomalyco/opencode/commit/78587c141bbac2c60b33c277359ba635b3410750
- https://github.com/open-telemetry/semantic-conventions-genai/commit/647791f1ad23fd7c427dce4a984b3efee40961fc
- https://arxiv.org/abs/2602.11988
- https://arxiv.org/abs/2606.15828
- https://github.com/ag-ui-protocol/ag-ui/commit/8cca436fcf60876de92eeb64aa4f667c456e2da9
- https://github.com/ag-ui-protocol/ag-ui/commit/0e35504f79f497d54a745decbba170b242f00dd8
- https://github.com/immich-app/immich/commit/507448d797d928ac3675c0e3a380f304925fcdea
- https://github.com/HumanSignal/label-studio/commit/6e228af069db3ea81d4d20d22f5009a61ddb6f2d
- https://github.com/microsoft/agent-framework/commit/f4e49958f3c7a30b78991a894080be397044e698
- https://github.com/openai/codex/commit/41efc6333e3ed476ce824aa729656f97503c10b8
- https://github.com/BerriAI/litellm/commit/dce1beadca59c06f41692ed67327d214c4793764
- https://github.com/langgenius/dify/commit/48e536ba391494052d24d238d92c79056fbec349
- https://github.com/aaif-goose/goose/commit/b56566ad781e0dd69f2a3eb6ac60cd489dcbcf23
- https://github.com/heygen-com/hyperframes/commit/1b4cf12cd35f25c6d7ae7cdd4ebd33be4ff6ab0b
- https://github.com/immich-app/immich/commit/ad7a9bd189286f1575dd27f76b36921fd153f414
- https://github.com/microsoft/BitNet/commit/e50e95e0ecc06c0f460eec95bb3e5da904f1bb34
- https://github.com/deepset-ai/haystack/commit/64e666c95e3d82fb440c213945f6c160b7955e0d
- https://github.com/laravel/mcp/commit/7f89e35f113f0b56e1edbd6ecc8eab4c0da6797e
- https://github.com/BerriAI/litellm/commit/2f03789927b26b712f47f7051b67517e94d3e8d7
- https://owasp.org/www-community/attacks/CSV_Injection
- https://github.com/openai/codex
- https://github.com/cline/cline
- https://cline.bot/
- https://github.com/RooCodeInc/Roo-Code
- https://github.com/aider-ai/aider
- https://aider.chat/
- https://github.com/continuedev/continue
- https://continue.dev/
- https://github.com/laravel/boost
- https://github.com/mostlygeek/llama-swap
- https://github.com/mudler/LocalAI
- https://github.com/kvcache-ai/ktransformers
- https://github.com/mlc-ai/mlc-llm
- https://github.com/DigitalArchivst/Open-Genealogy
- https://github.com/OpenHands/software-agent-sdk
- https://docs.openhands.dev/sdk/guides/agent-stuck-detector
- https://github.com/omnirexflora-labs/omnicoreagent
- https://github.com/swe-agent/swe-agent
- https://swe-agent.com/
- https://github.com/SWE-agent/mini-swe-agent
- https://mini-swe-agent.com/
- https://github.com/NousResearch/hermes-agent
- https://github.com/aaif-goose/goose
- https://goose-docs.ai/
- https://github.com/BerriAI/litellm
- https://github.com/BerriAI/litellm/commit/477ef3a7e2cc02488d97692d3f870a3dd39f2c20
- https://github.com/BerriAI/litellm/commit/f31dacbcd4b20a932fac1b9aa865b7fdbaa0ffe3
- https://github.com/BerriAI/litellm/commit/3f5ed5a9c8d651896a1caf37eb9867ddfb9dff32
- https://github.com/ggml-org/llama.cpp/commit/f6f12e43fa869ef0e008b99ed97dc4006bbb8907
- https://github.com/vllm-project/vllm/commit/75bdad40b5e96517c7f7645ce3199a8557817e17
- https://github.com/ultraworkers/claw-code
- https://claw-code.codes/
- https://github.com/laravel/framework
- https://laravel.com/
- https://github.com/laravel/horizon
- https://laravel.com/docs/horizon
- https://github.com/laravel/passport
- https://github.com/laravel/tinker
- https://github.com/laravel/pint
- https://github.com/laravel/pail
- https://github.com/laravel/sail
- https://github.com/laravel/vite-plugin
- https://github.com/vuejs/core
- https://vuejs.org/
- https://github.com/tailwindlabs/tailwindcss
- https://tailwindcss.com/
- https://www.postgresql.org/
- https://www.mysql.com/
- https://mariadb.org/
- https://redis.io/
- https://valkey.io/
- https://tika.apache.org/
- https://github.com/nextcloud/server
- https://github.com/nextcloud/docker
- https://www.thunderbird.net/
- https://www.mozilla.org/en-US/MPL/2.0/
- https://github.com/searxng/searxng
- https://docs.searxng.org/
- https://arxiv.org/abs/2602.17753
- https://aiagentindex.mit.edu/
- https://arxiv.org/abs/2404.16130
- https://arxiv.org/abs/2401.18059
- https://arxiv.org/abs/2210.07316
- https://arxiv.org/abs/2104.08663
- https://arxiv.org/abs/2408.08067
- https://aclanthology.org/2024.tacl-1.9/
- https://arxiv.org/abs/2506.05176
- https://arxiv.org/abs/2107.05720
- https://aclanthology.org/2022.naacl-main.272/
- https://joss.theoj.org/papers/10.21105/joss.00205
- https://github.com/pgvector/pgvector
- https://github.com/ollama/ollama
- https://modelcontextprotocol.io/specification/2025-11-25/server/tools
- https://github.com/modelcontextprotocol/modelcontextprotocol
- https://github.com/Portkey-AI/gateway
- https://portkey.ai/docs/product/observability/traces
- https://github.com/MrMySQL/youtube-transcript
- https://github.com/paperless-ngx/paperless-ngx
- https://github.com/paperless-ngx/paperless-ngx/blob/dev/docs/configuration.md
- https://github.com/graphlit/graphlit-mcp-server
- https://github.com/abdullahMASHUK/nextcloud-mcp-server
- https://gedcom.io/specifications/FamilySearchGEDCOMv7.html
- https://exiftool.org/TagNames/MWG.html
- https://exiftool.org/TagNames/XMP.html
- https://iptc.org/standards/photo-metadata/
- https://developer.adobe.com/xmp/docs/
- https://arxiv.org/abs/2607.09691
- https://github.com/ai-dynamo/dynamo
- https://arxiv.org/abs/2606.17949
- https://github.com/AKafakA/route-balance
- https://github.com/openssh/openssh-portable
- https://man.openbsd.org/ssh_config
- https://github.com/NVIDIA/skills
- https://github.com/openai/codex/compare/78d4a563f9ecaf5c331bfa4ae7332885a1d9e2f6...be39aab9f45fc76705590b063bc2480827571561
- https://github.com/voxel51/fiftyone
- https://github.com/inception-project/inception
- https://github.com/ag-ui-protocol/ag-ui
- https://github.com/openai/codex/commit/9ff47868eb2afeec579183e01bb9d3d3e9df2bcd
- https://github.com/ggml-org/llama.cpp/commit/a8dc0e3269a5378d212e6daea953fbbaa7ac8e4b
- https://github.com/ag-ui-protocol/ag-ui/commit/a379c3e37b4cb4a0aed15f8d99b58df45e34fa3f
- https://github.com/immich-app/immich/commit/fe3eb7d865d6f2acbf8af25e37a604617b3b1b52
- https://github.com/snyk/agent-scan/commit/06249396a44bb21722e665cd53852f762839a59c
- https://github.com/HumanSignal/label-studio/commit/4197452eadfad231969313ad82ed063ed7d4df93
- https://huggingface.co/Qwen/Qwen3.8-27B
- https://huggingface.co/ggml-org/Qwen3.8-27B-GGUF
- https://huggingface.co/ggml-org/Qwen3.6-35B-A3B-GGUF
- https://huggingface.co/nvidia/NVIDIA-Nemotron-3.5-Lightning-30B-A3B-BF16
- https://huggingface.co/ggml-org/NVIDIA-Nemotron-3.5-Lightning-30B-A3B-GGUF
- https://huggingface.co/zai-org/GLM-4.7-Flash
- https://huggingface.co/ggml-org/GLM-4.7-Flash-GGUF
- https://huggingface.co/moonshotai/Kimi-Linear-48B-A3B-Instruct
- https://huggingface.co/moonshotai/Moonlight-16B-A3B-Instruct
- https://github.com/ggml-org/llama.cpp
- https://github.com/JustVugg/colibri/commit/256bbe998bf1324fc725f8ff11e3dead957892fb
- https://github.com/JustVugg/colibri/pull/712
- https://github.com/JustVugg/colibri/pull/1087
- https://github.com/JustVugg/colibri/compare/256bbe998bf1324fc725f8ff11e3dead957892fb...ad7923694ac8448d2cc02290a506010ef9313f8a
- https://github.com/JustVugg/colibri/blob/ad7923694ac8448d2cc02290a506010ef9313f8a/c/serve_codec.h
- https://github.com/JustVugg/colibri/blob/ad7923694ac8448d2cc02290a506010ef9313f8a/c/kimi_k3.c
- https://github.com/JustVugg/colibri/blob/ad7923694ac8448d2cc02290a506010ef9313f8a/c/openai_server.py
- https://github.com/JustVugg/colibri/blob/ad7923694ac8448d2cc02290a506010ef9313f8a/docs/api.md

## Release use

The README should explain installation, useful workflows, scope, evidence and limits using the shipped source and current checks. A private test, install, runtime receipt or host benchmark does not establish a public release result. Publish synthetic examples and candidate-specific verification; keep private execution records outside the source release.

## Package and interface naming

The Laravel tool family includes Laravel Horizon, Laravel Passport, Laravel Tinker, Laravel Pint, Laravel Pail, Laravel Sail and Laravel Vite Plugin. These components retain their own Composer/npm package notices and locked version metadata. UI tooling uses Vue and Tailwind CSS.

The Graphlit MCP server remains a historical upstream integration reference; its unused npm dependency was removed from this public candidate. The Nextcloud MCP server is a separate optional locked package. FamilySearch GEDCOM 7, FindAGrave, BillionGraves and WikiTree are format/source references, not included private genealogy data or permission to copy another service's content. The Model Context Protocol package/source notices are preserved separately.
