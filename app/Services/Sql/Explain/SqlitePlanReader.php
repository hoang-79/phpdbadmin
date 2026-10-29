<?php

namespace App\Services\Sql\Explain;

/**
 * Plan von SQLite aus `EXPLAIN QUERY PLAN`: Zeilen mit Kennung, Elternkennung und Text («SCAN orders», «SEARCH
 * orders USING INDEX …», «USE TEMP B-TREE FOR ORDER BY»). SQLite schätzt keine Zeilen und misst nichts — der Hinweis
 * «vollständiger Durchlauf» kommt deshalb bei jedem SCAN ohne Index.
 *
 * @phpstan-import-type PlanNode from PlanReader
 * @phpstan-import-type PlanHint from PlanReader
 */
final class SqlitePlanReader extends PlanReader
{
    /**
     * @param  list<array{id: int, parent: int, detail: string}>  $rows
     * @return array{plan: PlanNode, hints: list<PlanHint>}
     */
    public function read(array $rows): array
    {
        $root = $this->node('QUERY PLAN');
        $root['children'] = $this->childrenOf(0, $rows);

        return $this->result($root);
    }

    /**
     * @param  list<array{id: int, parent: int, detail: string}>  $rows
     * @return list<PlanNode>
     */
    private function childrenOf(int $parent, array $rows): array
    {
        $children = [];

        foreach ($rows as $row) {
            if ($row['parent'] !== $parent || $row['id'] === $parent) {
                continue;
            }

            $id = $this->nextId();
            $detail = $row['detail'];
            $table = preg_match('/^(?:SCAN|SEARCH)\s+(?:TABLE\s+)?("[^"]+"|\S+)/', $detail, $match) === 1 ? trim($match[1], '"') : null;

            if ($table !== null && str_starts_with($detail, 'SCAN') && ! str_contains($detail, ' INDEX')) {
                $this->fullScan($id, $table, null);
            }

            if (str_starts_with($detail, 'USE TEMP B-TREE FOR ORDER BY') || str_starts_with($detail, 'USE TEMP B-TREE FOR RIGHT PART OF ORDER BY')) {
                $this->filesort($id);
            } elseif (str_starts_with($detail, 'USE TEMP B-TREE')) {
                $this->temporary($id);
            }

            $node = $this->node(
                preg_match('/^(SCAN|SEARCH|USE TEMP B-TREE|CORRELATED SCALAR SUBQUERY|SCALAR SUBQUERY|LIST SUBQUERY|MATERIALIZE|CO-ROUTINE|COMPOUND QUERY|MERGE|LEFT-MOST SUBQUERY|UNION ALL|UNION USING TEMP B-TREE|BLOOM FILTER)\b/', $detail, $operation) === 1 ? $operation[1] : $detail,
                $table,
                details: [$detail],
            );
            $node['children'] = $this->childrenOf($row['id'], $rows);
            $children[] = $node;
        }

        return $children;
    }
}
