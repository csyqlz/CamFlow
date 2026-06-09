(function () {
	function ready(fn) {
		if (document.readyState !== 'loading') {
			fn();
			return;
		}
		document.addEventListener('DOMContentLoaded', fn);
	}

	ready(function () {
		var tabs = document.querySelectorAll('[data-zibi-tab]');
		var panels = document.querySelectorAll('[data-zibi-panel]');
		var aiProvider = document.querySelector('[data-zibi-ai-provider]');
		var aiFields = document.querySelectorAll('.zibi-name-ai-field');
		var aiStatus = document.querySelector('[data-zibi-ai-status]');
		var modelList = document.querySelector('[data-zibi-model-list]');
		var fetchModelsButton = document.querySelector('[data-zibi-fetch-models]');
		var testAiButton = document.querySelector('[data-zibi-test-ai]');
		var modelOptions = document.getElementById('camflow-model-options');
		var aiForm = document.querySelector('[data-zibi-ai-form]');

		function setActiveTab(tabName, updateUrl) {
			if (!tabName) {
				return;
			}

			tabs.forEach(function (tab) {
				var isActive = tab.getAttribute('data-zibi-tab') === tabName;
				tab.classList.toggle('nav-tab-active', isActive);
				tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
			});

			panels.forEach(function (panel) {
				panel.classList.toggle('is-active', panel.getAttribute('data-zibi-panel') === tabName);
			});

			if (updateUrl && window.history && window.history.pushState) {
				var url = new URL(window.location.href);
				url.searchParams.set('tab', tabName);
				window.history.pushState({ zibiTab: tabName }, '', url.toString());
			}

			document.querySelectorAll('input[name="_wp_http_referer"]').forEach(function (input) {
				input.value = window.location.pathname + window.location.search;
			});
		}

		tabs.forEach(function (tab) {
			tab.addEventListener('click', function (event) {
				event.preventDefault();
				setActiveTab(tab.getAttribute('data-zibi-tab'), true);
			});
		});

		window.addEventListener('popstate', function () {
			var url = new URL(window.location.href);
			setActiveTab(url.searchParams.get('tab') || 'overview', false);
		});

		function updateAiFields() {
			if (!aiProvider) {
				return;
			}
			var provider = aiProvider.value || 'none';
			aiFields.forEach(function (row) {
				var show = row.classList.contains('is-' + provider);
				row.style.display = show ? '' : 'none';
			});
		}

		if (aiProvider) {
			aiProvider.addEventListener('change', updateAiFields);
			updateAiFields();
		}

		function optionInput(key) {
			var selector = '[name="zibi_name_options[' + key + ']"]';
			var scoped = aiForm ? aiForm.querySelector(selector + ':not([type="hidden"])') : null;
			return scoped || (aiForm ? aiForm.querySelector(selector) : null) || document.querySelector(selector);
		}

		function optionValue(key) {
			var input = optionInput(key);
			if (!input) {
				return '';
			}
			if (input.type === 'checkbox') {
				return input.checked ? input.value : '';
			}
			return input.value || '';
		}

		function activeProvider() {
			return aiProvider ? aiProvider.value || 'none' : 'none';
		}

		function activeModelInput() {
			var provider = activeProvider();
			if (provider === 'compatible') {
				return document.querySelector('[data-zibi-model-input="compatible"]');
			}
			if (provider === 'openrouter') {
				return document.querySelector('[data-zibi-model-input="openrouter"]');
			}
			if (provider === 'gemini') {
				return document.querySelector('[data-zibi-model-input="gemini"]');
			}
			return null;
		}

		function setAiStatus(message, type) {
			if (!aiStatus) {
				return;
			}
			aiStatus.textContent = message || '';
			aiStatus.classList.toggle('is-success', type === 'success');
			aiStatus.classList.toggle('is-error', type === 'error');
			aiStatus.classList.toggle('is-loading', type === 'loading');
		}

		function setButtonBusy(button, busy) {
			if (!button) {
				return;
			}
			if (!button.dataset.defaultText) {
				button.dataset.defaultText = button.textContent;
			}
			button.disabled = busy;
			button.textContent = busy ? '处理中...' : button.dataset.defaultText;
		}

		function aiPayload(action) {
			var data = new FormData();
			data.append('action', action);
			data.append('nonce', window.CamFlowAi ? CamFlowAi.nonce : '');
			[
				'ai_provider',
				'compatible_api_base',
				'compatible_api_key',
				'compatible_model',
				'openrouter_api_key',
				'openrouter_model',
				'gemini_api_key',
				'ai_model',
				'ai_timeout'
			].forEach(function (key) {
				data.append(key, key === 'ai_provider' ? activeProvider() : optionValue(key));
			});
			return data;
		}

		function requestAiTool(action, button, onSuccess) {
			if (!window.CamFlowAi || !CamFlowAi.ajaxUrl) {
				setAiStatus('后台脚本未加载完整，请刷新页面后重试。', 'error');
				return;
			}

			setButtonBusy(button, true);
			setAiStatus(action === 'zibi_name_fetch_ai_models' ? '正在读取模型列表...' : '正在测试连接...', 'loading');

			fetch(CamFlowAi.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: aiPayload(action)
			})
				.then(function (response) {
					return response.json();
				})
				.then(function (payload) {
					if (!payload || !payload.success) {
						throw new Error(payload && payload.data && payload.data.message ? payload.data.message : '请求失败。');
					}
					onSuccess(payload.data || {});
				})
				.catch(function (error) {
					setAiStatus(error.message || '请求失败。', 'error');
				})
				.finally(function () {
					setButtonBusy(button, false);
				});
		}

		function addModelOption(model) {
			if (!modelOptions || !model) {
				return;
			}
			var exists = Array.prototype.some.call(modelOptions.children, function (option) {
				return option.value === model;
			});
			if (exists) {
				return;
			}
			var option = document.createElement('option');
			option.value = model;
			modelOptions.appendChild(option);
		}

		function renderModelList(models) {
			if (!modelList) {
				return;
			}
			modelList.innerHTML = '';
			if (!models || !models.length) {
				modelList.hidden = true;
				return;
			}

			models.forEach(addModelOption);
			models.slice(0, 120).forEach(function (model) {
				var button = document.createElement('button');
				button.type = 'button';
				button.className = 'button button-small zibi-name-model-choice';
				button.dataset.zibiModelChoice = model;
				button.textContent = model;
				modelList.appendChild(button);
			});

			if (models.length > 120) {
				var more = document.createElement('span');
				more.className = 'zibi-name-model-more';
				more.textContent = '其余模型已加入输入框候选项';
				modelList.appendChild(more);
			}
			modelList.hidden = false;
		}

		if (fetchModelsButton) {
			fetchModelsButton.addEventListener('click', function () {
				requestAiTool('zibi_name_fetch_ai_models', fetchModelsButton, function (data) {
					renderModelList(data.models || []);
					setAiStatus(data.message || '模型列表已读取。', 'success');
				});
			});
		}

		if (testAiButton) {
			testAiButton.addEventListener('click', function () {
				requestAiTool('zibi_name_test_ai_connection', testAiButton, function (data) {
					setAiStatus(data.message || '连接成功。', 'success');
				});
			});
		}

		if (modelList) {
			modelList.addEventListener('click', function (event) {
				var button = event.target.closest('[data-zibi-model-choice]');
				var input = activeModelInput();
				if (!button || !input) {
					return;
				}
				input.value = button.dataset.zibiModelChoice;
				input.dispatchEvent(new Event('input', { bubbles: true }));
				input.dispatchEvent(new Event('change', { bubbles: true }));
				setAiStatus('已填入模型：' + button.dataset.zibiModelChoice, 'success');
			});
		}

		document.querySelectorAll('[data-zibi-confirm]').forEach(function (button) {
			button.addEventListener('click', function (event) {
				if (!window.confirm(button.getAttribute('data-zibi-confirm'))) {
					event.preventDefault();
				}
			});
		});

		document.querySelectorAll('.zibi-name-check-all').forEach(function (checkbox) {
			checkbox.addEventListener('change', function () {
				var table = checkbox.closest('table');
				if (!table) {
					return;
				}
				table.querySelectorAll('tbody input[type="checkbox"]').forEach(function (item) {
					item.checked = checkbox.checked;
				});
			});
		});
	});
})();
