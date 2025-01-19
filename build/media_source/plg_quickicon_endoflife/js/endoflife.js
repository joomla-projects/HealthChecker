/**
 * @copyright   (C) 2024 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
*/

(document => {

	// Ajax call to get the update status of the installed extension
	const fetchEndOfLifeData = () => {
		if (Joomla.getOptions('js-endoflife-check')) {
		    const options = Joomla.getOptions('js-endoflife-check');
		    const update = (type, text, linkHref) => {
			    const link = document.getElementById('plg_quickicon_endoflife_' + options.key);
			    const linkSpans = [].slice.call(link.querySelectorAll('span.j-links-link'));
			    if (link) {
			      link.classList.add(type);
		          if (linkHref) {
		            link.setAttribute('href', linkHref);
		          }
			    }
			    if (linkSpans.length) {
			      linkSpans.forEach(span => {
			        span.innerHTML = Joomla.sanitizeHtml(text);
			      });
			    }
			};

		    /**
		     * DO NOT use fetch() for QuickIcon requests. They must be queued.
		     *
		     * @see https://github.com/joomla/joomla-cms/issues/38001
		     */
		    Joomla.enqueueRequest({
		      url: 'http://endoflife.date/api/' + options.key + '/' + options.version + '.json',
		      method: 'GET',
		      promise: true
		    }).then(xhr => {
		      const response = xhr.responseText;
		      const request = JSON.parse(response);
		      if (Array.isArray(request)) {
		        if (request.length === 0) {
		          // No updates
		          update('info', Joomla.Text._('PLG_QUICKICON_ENDOFLIFE_STATUS_UNKNOWN'));
		        } else {
		          const productInfo = request.shift();
				      console.log('support: ' + productInfo.support);
				      console.log('eol: ' + productInfo.eol);
				      console.log('today: : ' + new Date());
		        }
		      } else {
		        // An error occurred
		        update('info', Joomla.Text._('PLG_QUICKICON_ENDOFLIFE_STATUS_UNKNOWN'));
		      }
		    }).catch(() => {
		      // An error occurred
		      update('info', Joomla.Text._('PLG_QUICKICON_ENDOFLIFE_STATUS_UNKNOWN'));
		    });
		}
	};

	// Give some times to the layout and other scripts to settle their stuff
	window.addEventListener('load', () => {
		setTimeout(fetchEndOfLifeData, 360);
	});
})(document);
