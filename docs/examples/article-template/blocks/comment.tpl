@block commentItem(comment: Comment)
<article class="comment">
  @if comment.avatar
  <img class="avatar" src="{{comment.avatar}}" alt="{{comment.name}}" loading="lazy">
  @endif
  <div>
    <p class="comment-meta"><strong>{{comment.name}}</strong>
      @if comment.verified
      <span class="badge">Verified reader</span>
      @endif
      <span>{{comment.date}}</span>
    </p>
    <p class="comment-body">{{comment.body}}</p>
  </div>
</article>
@endblock

@block commentsList(comments: Comments)
@if comments.enabled
<section class="comments" aria-label="{{comments.title}}">
  <h2>{{comments.title}}</h2>
  <div class="comment-list">
    @each comment in comments.items:
    @render commentItem(comment)
    @endeach
  </div>
</section>
@endif
@endblock
