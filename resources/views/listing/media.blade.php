<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Media Listing</title>
    <style>
        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: #f5f6f8;
            color: #1f2937;
        }

        .container {
            max-width: 98%;
            margin: 20px auto;
            background: #fff;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            overflow: hidden;
        }

        .header {
            padding: 12px 16px;
            border-bottom: 1px solid #e5e7eb;
            font-size: 18px;
            font-weight: 600;
        }

        .table-wrap {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1200px;
        }

        th,
        td {
            border: 1px solid #e5e7eb;
            padding: 8px 10px;
            text-align: left;
            vertical-align: top;
            font-size: 13px;
            white-space: nowrap;
        }

        th {
            background: #f3f4f6;
            font-weight: 600;
        }

        tr:nth-child(even) {
            background: #fafafa;
        }

        .empty {
            padding: 16px;
            color: #6b7280;
        }

        .pagination {
            padding: 12px 16px;
            border-top: 1px solid #e5e7eb;
        }

        .pagination nav > div:first-child {
            display: none;
        }

        .pagination svg {
            width: 18px;
            height: 18px;
        }

        .pagination span,
        .pagination a {
            font-size: 13px;
        }

        .sortable {
            cursor: pointer;
            text-decoration: none;
            color: inherit;
            display: block;
            padding: 8px 10px;
            margin: -8px -10px;
        }

        .sortable:hover {
            background: #e5e7eb;
        }

        .sortable.active {
            background: #ddd6fe;
            font-weight: 700;
        }

        .sort-indicator {
            margin-left: 5px;
            font-size: 11px;
            opacity: 0.7;
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">Media Listing</div>

    @if ($media->count() === 0)
        <div class="empty">No media records found.</div>
    @else
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    @foreach ($columns as $column)
                        <th>
                            <a href="?sort={{ $column }}&direction={{ $sortColumn === $column && $sortDirection === 'asc' ? 'desc' : 'asc' }}"
                                class="sortable {{ $sortColumn === $column ? 'active' : '' }}">
                                {{ $column }}
                                @if ($sortColumn === $column)
                                    <span class="sort-indicator">{{ $sortDirection === 'asc' ? '▲' : '▼' }}</span>
                                @endif
                            </a>
                        </th>
                    @endforeach
                </tr>
                </thead>
                <tbody>
                @foreach ($media as $row)
                    <tr>
                        @foreach ($columns as $column)
                            <td>{{ $row->{$column} ?? '' }}</td>
                        @endforeach
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        <div class="pagination">
            {{ $media->links() }}
        </div>
    @endif
</div>
</body>
</html>
