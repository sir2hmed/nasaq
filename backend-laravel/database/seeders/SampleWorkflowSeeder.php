<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\WorkflowGraphSynchronizer;
use Illuminate\Database\Seeder;

class SampleWorkflowSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::query()->updateOrCreate(
            ['email' => 'test@example.com'],
            [
                'name' => 'Test User',
                'password' => 'NasaqDemo2026',
                'preferred_locale' => 'en',
            ],
        );

        $graph = [
            'version' => 1,
            'name' => 'Research to Article',
            'description' => 'Deterministic demo workflow for the core Nasaq AI path.',
            'nodes' => [
                [
                    'id' => 'researcher_01',
                    'type' => 'researcher',
                    'position' => ['x' => 120, 'y' => 180],
                    'config' => [
                        'topic' => 'AI agents in education',
                        'source_count' => 5,
                        'language' => 'en',
                        'search_depth' => 'basic',
                    ],
                ],
                [
                    'id' => 'writer_01',
                    'type' => 'writer',
                    'position' => ['x' => 460, 'y' => 180],
                    'config' => [
                        'style' => 'professional',
                        'length' => 'medium',
                        'format' => 'article',
                        'language' => 'same_as_input',
                    ],
                ],
                [
                    'id' => 'export_01',
                    'type' => 'export',
                    'position' => ['x' => 800, 'y' => 180],
                    'config' => ['formats' => ['markdown', 'pdf', 'docx']],
                ],
            ],
            'edges' => [
                [
                    'id' => 'edge_researcher_writer',
                    'source' => 'researcher_01',
                    'target' => 'writer_01',
                ],
                [
                    'id' => 'edge_writer_export',
                    'source' => 'writer_01',
                    'target' => 'export_01',
                ],
            ],
        ];

        $graphs = [
            $graph,
            $this->researchToVideo(),
            $this->contentPublishing(),
            $this->campaignDistribution(),
            $this->fullDemo(),
        ];

        foreach ($graphs as $template) {
            $workflow = $user->workflows()->updateOrCreate(
                ['name' => $template['name']],
                [
                    'description' => $template['description'],
                    'graph_json' => $template,
                    'status' => 'draft',
                    'version' => 1,
                ],
            );

            app(WorkflowGraphSynchronizer::class)->sync($workflow);
        }
    }

    /** @return array<string, mixed> */
    private function researchToVideo(): array
    {
        return [
            'version' => 1,
            'name' => 'Research to Video',
            'description' => 'Research, script, render a real MP4, and export production notes.',
            'nodes' => [
                $this->researcher('Visual storytelling with AI agents'),
                $this->writer('script'),
                $this->node('video_01', 'video', 760, [
                    'scene_duration' => 3,
                    'max_scenes' => 6,
                    'narration' => 'silent',
                    'voice' => 'alloy',
                ]),
                $this->node('export_01', 'export', 1080, [
                    'formats' => ['markdown', 'pdf', 'docx'],
                ]),
            ],
            'edges' => [
                $this->edge('edge_research_writer', 'researcher_01', 'writer_01'),
                $this->edge('edge_writer_video', 'writer_01', 'video_01'),
                $this->edge('edge_video_export', 'video_01', 'export_01'),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function contentPublishing(): array
    {
        return [
            'version' => 1,
            'name' => 'Content Publishing',
            'description' => 'Research, write, review, and publish approved content.',
            'nodes' => [
                $this->researcher('Human-reviewed content automation'),
                $this->writer('article'),
                $this->node('approval_01', 'approval', 760, []),
                $this->node('publisher_01', 'publisher', 1080, [
                    'destination' => 'google_drive',
                    'privacy_status' => 'private',
                ]),
            ],
            'edges' => [
                $this->edge('edge_research_writer', 'researcher_01', 'writer_01'),
                $this->edge('edge_writer_approval', 'writer_01', 'approval_01'),
                $this->edge('edge_approval_publisher', 'approval_01', 'publisher_01'),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function campaignDistribution(): array
    {
        return [
            'version' => 1,
            'name' => 'Campaign Distribution',
            'description' => 'Publish generated content and distribute its link by email.',
            'nodes' => [
                $this->researcher('Bilingual campaign distribution'),
                $this->writer('article'),
                $this->node('publisher_01', 'publisher', 760, [
                    'destination' => 'google_drive',
                    'privacy_status' => 'private',
                ]),
                $this->node('email_01', 'email', 1080, [
                    'recipients' => ['reviewer@example.test'],
                    'subject' => 'Your Nasaq campaign content',
                    'body_template' => "Your generated campaign is ready:\n\n{links}",
                ]),
            ],
            'edges' => [
                $this->edge('edge_research_writer', 'researcher_01', 'writer_01'),
                $this->edge('edge_writer_publisher', 'writer_01', 'publisher_01'),
                $this->edge('edge_publisher_email', 'publisher_01', 'email_01'),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function fullDemo(): array
    {
        return [
            'version' => 1,
            'name' => 'Full Product Demo',
            'description' => 'Every final agent with video, review, simulated distribution, and export.',
            'nodes' => [
                $this->researcher('Secure bilingual visual AI workflows'),
                $this->writer('script'),
                $this->node('video_01', 'video', 760, [
                    'scene_duration' => 2,
                    'max_scenes' => 5,
                    'narration' => 'silent',
                    'voice' => 'alloy',
                ], 100),
                $this->node('approval_01', 'approval', 1020, [], 100),
                $this->node('publisher_01', 'publisher', 1280, [
                    'destination' => 'youtube',
                    'privacy_status' => 'private',
                ], 100),
                $this->node('email_01', 'email', 1540, [
                    'recipients' => ['reviewer@example.test'],
                    'subject' => 'Nasaq full demo video',
                    'body_template' => "The approved demo video is ready:\n\n{links}",
                ], 100),
                $this->node('export_01', 'export', 1020, [
                    'formats' => ['markdown', 'pdf', 'docx'],
                ], 380),
            ],
            'edges' => [
                $this->edge('edge_research_writer', 'researcher_01', 'writer_01'),
                $this->edge('edge_writer_video', 'writer_01', 'video_01'),
                $this->edge('edge_video_approval', 'video_01', 'approval_01'),
                $this->edge('edge_approval_publisher', 'approval_01', 'publisher_01'),
                $this->edge('edge_publisher_email', 'publisher_01', 'email_01'),
                $this->edge('edge_video_export', 'video_01', 'export_01'),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function researcher(string $topic): array
    {
        return $this->node('researcher_01', 'researcher', 120, [
            'topic' => $topic,
            'source_count' => 5,
            'language' => 'en',
            'search_depth' => 'basic',
        ]);
    }

    /** @return array<string, mixed> */
    private function writer(string $format): array
    {
        return $this->node('writer_01', 'writer', 440, [
            'style' => 'professional',
            'length' => 'medium',
            'format' => $format,
            'language' => 'same_as_input',
        ]);
    }

    /** @param array<string, mixed> $config @return array<string, mixed> */
    private function node(string $id, string $type, int $x, array $config, int $y = 180): array
    {
        return [
            'id' => $id,
            'type' => $type,
            'position' => ['x' => $x, 'y' => $y],
            'config' => $config,
        ];
    }

    /** @return array<string, string> */
    private function edge(string $id, string $source, string $target): array
    {
        return ['id' => $id, 'source' => $source, 'target' => $target];
    }
}
