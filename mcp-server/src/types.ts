export interface Workflow {
  id: number;
  name: string;
  description: string | null;
  schedule: string | null;
  active: boolean;
  error_handling: 'stop' | 'continue';
  created_at: Date;
  updated_at: Date;
}

export interface WorkflowNode {
  id: number;
  workflow_id: number;
  node_type: string;
  node_order: number;
  created_at: Date;
}

export interface WorkflowRun {
  id: number;
  workflow_id: number;
  status: 'running' | 'completed' | 'failed';
  error_message: string | null;
  started_at: Date;
  completed_at: Date | null;
}

export interface NodeExecution {
  id: number;
  run_id: number;
  workflow_node_id: number;
  node_type: string;
  node_order: number;
  duration_ms: number | null;
  error_message: string | null;
  executed_at: Date;
}

export interface ArtisanCommand {
  command: string;
  description: string;
  category: string;
}

export interface SystemDiagnostics {
  laravel_version: string;
  php_version: string;
  database_status: string;
  active_workflows: number;
  total_runs: number;
  disk_space_gb: number;
}
