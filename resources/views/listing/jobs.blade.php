<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Queue Jobs Listing</title>
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
        }

        .section {
            background: #fff;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            overflow: hidden;
            margin-bottom: 18px;
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
    </style>
</head>
<body>
<div class="container">
    <div class="section">
        <div class="header">Jobs Table</div>

        @if ($jobs->count() === 0)
            <div class="empty">No jobs records found.</div>
        @else
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        @foreach ($jobsColumns as $column)
                            <th>{{ $column }}</th>
                        @endforeach
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($jobs as $row)
                        <tr>
                            @foreach ($jobsColumns as $column)
                                <td>{{ $row->{$column} ?? '' }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>

            <div class="pagination">
                {{ $jobs->appends(request()->except('jobs_page'))->links() }}
            </div>
        @endif
    </div>

    <div class="section">
        <div class="header">Failed Jobs Table</div>

        @if ($failedJobs->count() === 0)
            <div class="empty">No failed_jobs records found.</div>
        @else
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        @foreach ($failedJobsColumns as $column)
                            <th>{{ $column }}</th>
                        @endforeach
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($failedJobs as $row)
                        <tr>
                            @foreach ($failedJobsColumns as $column)
                                <td>{{ $row->{$column} ?? '' }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>

            <div class="pagination">
                {{ $failedJobs->appends(request()->except('failed_jobs_page'))->links() }}
            </div>
        @endif
    </div>
</div>
</body>
</html>
