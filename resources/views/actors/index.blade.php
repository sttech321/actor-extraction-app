@extends('layouts.app')

@section('content')
    <div class="card">
        <div class="card-body">
            <h4>Past Submissions</h4>
            <table class="table table-bordered mt-3">
                <thead>
                    <tr>
                        <th>First Name</th>
                        <th>Address</th>
                        <th>Gender</th>
                        <th>Height</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($actors as $actor)
                        <tr>
                            <td>{{ $actor->first_name }} {{ $actor->last_name }}</td>
                            <td style="max-width:300px;white-space:pre-wrap;">{{ $actor->address }}</td>
                            <td>{{ $actor->gender ?? '-' }}</td>
                            <td>{{ $actor->height ?? '-' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4">No submissions yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
            <a class="btn btn-secondary" href="{{ route('actors.create') }}">New submission</a>
        </div>
    </div>
@endsection