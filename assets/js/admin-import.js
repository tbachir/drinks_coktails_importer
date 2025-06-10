/**
 * Script pour l'interface d'import admin
 * 
 * @package Drinks_Cocktails_Import
 */

(function($) {
    'use strict';
    
    const DCIImport = {
        
        // Éléments DOM
        elements: {
            startButton: null,
            form: null,
            progressSection: null,
            resultsSection: null,
            progressBar: null,
            progressText: null,
            progressPercent: null,
            resultsStats: null,
            logsContainer: null
        },
        
        // État de l'import
        state: {
            running: false,
            checkInterval: null
        },
        
        /**
         * Initialisation
         */
        init: function() {
            this.cacheElements();
            this.bindEvents();
        },
        
        /**
         * Mettre en cache les éléments DOM
         */
        cacheElements: function() {
            this.elements.startButton = $('#dci-start-import');
            this.elements.form = $('#dci-import-form');
            this.elements.progressSection = $('#dci-progress-section');
            this.elements.resultsSection = $('#dci-results-section');
            this.elements.progressBar = $('.dci-progress-fill');
            this.elements.progressText = $('.dci-progress-status');
            this.elements.progressPercent = $('.dci-progress-percent');
            this.elements.resultsStats = $('.dci-results-stats');
            this.elements.logsContainer = $('.dci-logs-container');
        },
        
        /**
         * Attacher les événements
         */
        bindEvents: function() {
            this.elements.startButton.on('click', this.startImport.bind(this));
        },
        
        /**
         * Démarrer l'import
         */
        startImport: function(e) {
            e.preventDefault();
            
            if (this.state.running) {
                return;
            }
            
            // Confirmation
            if (!confirm(dci_import.strings.confirm_import)) {
                return;
            }
            
            // Collecter les données du formulaire
            const formData = new FormData(this.elements.form[0]);
            formData.append('action', 'dci_run_import');
            formData.append('nonce', dci_import.nonce);
            
            // Préparer l'interface
            this.setImportState(true);
            this.showProgress();
            this.updateProgress(0, dci_import.strings.import_running);
            
            // Lancer la requête AJAX
            $.ajax({
                url: dci_import.ajax_url,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: this.handleImportSuccess.bind(this),
                error: this.handleImportError.bind(this),
                complete: this.handleImportComplete.bind(this)
            });
            
            // Démarrer le suivi de progression
            this.startProgressTracking();
        },
        
        /**
         * Gérer le succès de l'import
         */
        handleImportSuccess: function(response) {
            if (response.success && response.data) {
                this.displayResults(response.data);
                this.updateProgress(100, dci_import.strings.import_complete);
            } else {
                this.handleImportError();
            }
        },
        
        /**
         * Gérer l'erreur de l'import
         */
        handleImportError: function() {
            this.updateProgress(0, dci_import.strings.import_error);
            this.addLog('error', dci_import.strings.import_error);
        },
        
        /**
         * Gérer la fin de l'import
         */
        handleImportComplete: function() {
            this.setImportState(false);
            this.stopProgressTracking();
        },
        
        /**
         * Définir l'état de l'import
         */
        setImportState: function(running) {
            this.state.running = running;
            this.elements.startButton.prop('disabled', running);
            this.elements.form.find('input').prop('disabled', running);
        },
        
        /**
         * Afficher la section de progression
         */
        showProgress: function() {
            this.elements.progressSection.slideDown();
            this.elements.resultsSection.hide();
        },
        
        /**
         * Mettre à jour la progression
         */
        updateProgress: function(percent, status) {
            this.elements.progressBar.css('width', percent + '%');
            this.elements.progressPercent.text(percent + '%');
            
            if (status) {
                this.elements.progressText.text(status);
            }
        },
        
        /**
         * Démarrer le suivi de progression
         */
        startProgressTracking: function() {
            // Simuler la progression pour l'instant
            let progress = 0;
            this.state.checkInterval = setInterval(() => {
                if (progress < 90) {
                    progress += Math.random() * 10;
                    this.updateProgress(Math.min(progress, 90));
                }
            }, 1000);
        },
        
        /**
         * Arrêter le suivi de progression
         */
        stopProgressTracking: function() {
            if (this.state.checkInterval) {
                clearInterval(this.state.checkInterval);
                this.state.checkInterval = null;
            }
        },
        
        /**
         * Afficher les résultats
         */
        displayResults: function(data) {
            // Afficher la section des résultats
            this.elements.resultsSection.slideDown();
            
            // Afficher les statistiques
            if (data.stats) {
                this.displayStats(data.stats);
            }
            
            // Afficher les logs
            if (data.logs) {
                this.displayLogs(data.logs);
            }
        },
        
        /**
         * Afficher les statistiques
         */
        displayStats: function(stats) {
            this.elements.resultsStats.empty();
            
            const statBoxes = [
                {
                    label: 'Drinks importés',
                    value: stats.drinks_imported || 0,
                    class: 'success'
                },
                {
                    label: 'Drinks mis à jour',
                    value: stats.drinks_updated || 0,
                    class: 'info'
                },
                {
                    label: 'Cocktails importés',
                    value: stats.cocktails_imported || 0,
                    class: 'success'
                },
                {
                    label: 'Cocktails mis à jour',
                    value: stats.cocktails_updated || 0,
                    class: 'info'
                },
                {
                    label: 'Images en attente',
                    value: stats.images_queued || 0,
                    class: 'warning'
                },
                {
                    label: 'Erreurs',
                    value: stats.errors || 0,
                    class: stats.errors > 0 ? 'error' : 'success'
                }
            ];
            
            statBoxes.forEach(stat => {
                const box = $('<div>')
                    .addClass('dci-stat-box')
                    .addClass('dci-stat-' + stat.class)
                    .html(`
                        <div class="dci-stat-number">${stat.value}</div>
                        <div class="dci-stat-label">${stat.label}</div>
                    `);
                
                this.elements.resultsStats.append(box);
            });
        },
        
        /**
         * Afficher les logs
         */
        displayLogs: function(logs) {
            this.elements.logsContainer.empty();
            
            logs.forEach(log => {
                this.addLog(log.type, log.message, log.time);
            });
            
            // Scroller vers le bas
            this.elements.logsContainer.scrollTop(this.elements.logsContainer[0].scrollHeight);
        },
        
        /**
         * Ajouter un log
         */
        addLog: function(type, message, time) {
            const timestamp = time ? new Date(time).toLocaleTimeString() : new Date().toLocaleTimeString();
            
            const logEntry = $('<div>')
                .addClass('dci-log-entry')
                .addClass('dci-log-' + type)
                .text(`[${timestamp}] ${message}`);
            
            this.elements.logsContainer.append(logEntry);
        }
    };
    
    // Initialiser au chargement du DOM
    $(document).ready(function() {
        DCIImport.init();
    });
    
})(jQuery);